<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SignupController extends Controller
{
    public function signup(Request $request, PaystackService $paystack)
    {
        try {
            // DEBUG: return host and environment to isolate DB issues
            $host = $request->getHost();
            $env = env('APP_ENV');
            return response()->json(['host' => $host, 'env' => $env]);

            $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:100', 'unique:tenants,slug'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
            'plan' => ['nullable', 'string'],
        ]);

        // Create tenant
        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['slug']),
            'status' => 'pending',
            'settings' => [],
        ]);

        // Create tenant admin
        $admin = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => Hash::make($validated['admin_password']),
            'role' => 'admin',
        ]);

        // Initialize Paystack transaction for subscription (if plan provided)
        $payment = null;
        if (! empty($validated['plan'])) {
            $init = $paystack->initializeTransaction([
                'email' => $admin->email,
                'amount' => (int) (1000 * 100), // placeholder 1000 NGN, amount in kobo
                'metadata' => ['tenant_id' => $tenant->id, 'plan' => $validated['plan']],
                'reference' => 'TENANT_' . Str::upper(Str::random(12)),
            ]);

            $payment = $init;
        }
            return response()->json([
                'tenant' => $tenant,
                'admin' => ['id' => $admin->id, 'email' => $admin->email],
                'payment' => $payment,
            ], 201);

        } catch (\Throwable $e) {
            logger()->error('Signup error', ['err' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Signup failed', 'error' => $e->getMessage()], 500);
        }
    }
}
