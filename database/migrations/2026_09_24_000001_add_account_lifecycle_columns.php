<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account lifecycle: deactivate (reversible) and delete (a 30-day, cancellable
 * purge window) for students, staff and whole academies.
 *
 * Three independent state machines, deliberately NOT merged with anything that
 * already exists:
 *
 *   1. `lms_students.is_active` — the reversible login freeze. Staff already had
 *      this column (it gates staff login, see StaffAuthController::login);
 *      students had nothing, so an academy had no way to bench a student short
 *      of a hard delete that cascades to their certificates.
 *   2. `deactivated_at` / `purge_after` on both person tables.
 *   3. The same pair on `tenants`, plus `purge_requested_by` for the audit trail.
 *
 * `tenants.status` is deliberately left ALONE: it is a provisioning marker, and
 * TenantSignupController::verify() reads `status === 'active'` to mean "already
 * provisioned, idempotent success". Writing 'deactivated' there would make a
 * re-verify re-provision the academy. Lifecycle state is therefore derived from
 * `deactivated_at` / `purge_after` (Tenant::lifecycleState()), kept parallel to
 * subscriptionState() and never merged with it: a billing `frozen` and a
 * voluntary `deactivated` are different things and must read differently.
 *
 * Additive + guarded (`hasColumn` / try-catch on every index), so re-running on a
 * shared host is safe. One column per `Schema::table` call because MySQL cannot
 * combine several ADD COLUMN statements with an ADD INDEX in one ALTER.
 */
return new class extends Migration
{
    /** Person tables gaining the deactivate + purge-window pair. */
    private array $personTables = ['lms_students', 'lms_teachers'];

    public function up(): void
    {
        foreach ($this->personTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Students have no active flag yet; staff already do, so this is a
            // no-op for them and the guard is what keeps the migration re-runnable.
            if (! Schema::hasColumn($table, 'is_active')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->boolean('is_active')->default(true);
                });
            }

            if (! Schema::hasColumn($table, 'deactivated_at')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dateTime('deactivated_at')->nullable();
                });
            }

            if (! Schema::hasColumn($table, 'purge_after')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dateTime('purge_after')->nullable();
                });
            }

            // Stamped when the purge actually ran. Without it a purged account is
            // indistinguishable from a merely deactivated one, and the academy's
            // roster would show "Deactivated" for someone who is gone.
            if (! Schema::hasColumn($table, 'purged_at')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dateTime('purged_at')->nullable();
                });
            }

            // Whether THIS purge is what froze the account, as opposed to the
            // account already being deactivated when the purge was scheduled.
            // Cancelling a purge has to put the account back exactly where it was:
            // without this flag the only options are always-restore (silently
            // handing access back to someone an academy had switched off) or
            // never-restore (breaking the "restorable until <date>" promise shown
            // on screen).
            if (! Schema::hasColumn($table, 'purge_froze_access')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->boolean('purge_froze_access')->default(false);
                });
            }

            // The purge sweep's only filter is `purge_after <= now()`, which is
            // NULL for the overwhelming majority of rows. try/catch keeps the
            // index add DB-agnostic and re-run safe.
            $this->index($table, 'purge_after', $table . '_purge_after_idx');
        }

        if (Schema::hasTable('tenants')) {
            if (! Schema::hasColumn('tenants', 'deactivated_at')) {
                Schema::table('tenants', function (Blueprint $t): void {
                    $t->dateTime('deactivated_at')->nullable();
                });
            }

            if (! Schema::hasColumn('tenants', 'purge_after')) {
                Schema::table('tenants', function (Blueprint $t): void {
                    $t->dateTime('purge_after')->nullable();
                });
            }

            // Who at the platform scheduled the purge — nullable because an
            // owner-initiated deactivation has no platform actor, and because the
            // user row may itself be gone by the time anyone reads this.
            if (! Schema::hasColumn('tenants', 'purge_requested_by')) {
                Schema::table('tenants', function (Blueprint $t): void {
                    $t->unsignedBigInteger('purge_requested_by')->nullable();
                });
            }

            if (! Schema::hasColumn('tenants', 'purged_at')) {
                Schema::table('tenants', function (Blueprint $t): void {
                    $t->dateTime('purged_at')->nullable();
                });
            }

            // See the person-table twin above. Matters most here: a purge is
            // usually scheduled on an academy its owner had ALREADY paused and
            // abandoned, so cancelling must not quietly re-open a storefront that
            // was deliberately closed.
            if (! Schema::hasColumn('tenants', 'purge_froze_access')) {
                Schema::table('tenants', function (Blueprint $t): void {
                    $t->boolean('purge_froze_access')->default(false);
                });
            }

            $this->index('tenants', 'purge_after', 'tenants_purge_after_idx');
            $this->index('tenants', 'deactivated_at', 'tenants_deactivated_at_idx');
        }
    }

    public function down(): void
    {
        foreach ($this->personTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->dropIndex($table, $table . '_purge_after_idx');

            Schema::table($table, function (Blueprint $t) use ($table): void {
                foreach (['deactivated_at', 'purge_after', 'purged_at', 'purge_froze_access'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $t->dropColumn($column);
                    }
                }
                // `is_active` pre-existed on lms_teachers, so it is intentionally
                // left in place on both tables rather than guessed at.
            });
        }

        if (Schema::hasTable('tenants')) {
            $this->dropIndex('tenants', 'tenants_purge_after_idx');
            $this->dropIndex('tenants', 'tenants_deactivated_at_idx');

            Schema::table('tenants', function (Blueprint $t): void {
                foreach (['purge_requested_by', 'purge_after', 'deactivated_at', 'purged_at', 'purge_froze_access'] as $column) {
                    if (Schema::hasColumn('tenants', $column)) {
                        $t->dropColumn($column);
                    }
                }
            });
        }
    }

    /** Add an index, ignoring "already exists" (and non-MySQL drivers). */
    private function index(string $table, string $column, string $name): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $t) use ($column, $name): void {
                $t->index($column, $name);
            });
        } catch (\Throwable $e) {
            // Already present, or the driver does not support it — either way the
            // column is what matters and the query still runs.
        }
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! $this->hasIndex($table, $name)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $t) use ($name): void {
                $t->dropIndex($name);
            });
        } catch (\Throwable $e) {
            // Nothing to drop.
        }
    }

    /** Whether an index exists — `hasIndex` is unavailable on some drivers. */
    private function hasIndex(string $table, string $index): bool
    {
        try {
            return ! empty(DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]));
        } catch (\Throwable $e) {
            return false;
        }
    }
};
