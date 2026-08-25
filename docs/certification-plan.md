# Certification — plan & how it works

_Last updated: 2026-08-23_

## Decision (what the user asked for)
> "draft out the right plan for the certification, that should not be a staff thing
> so remove it from the staff sidebar, then put it on the admin"

Certification is an **institute (admin/owner) responsibility**, not a staff one.
A certificate is a record the institute stands behind, so issuing it belongs with
the people who own the institute's brand and its student records — not with an
individual instructor.

## What shipped now (v1 — manual issue)
The smallest thing that is correct, on-brand, and production-safe for launch.

- **Removed** the "Certificates" link from the **staff** sidebar
  (`StaffSidebar.tsx`, Content group).
- The old staff page at `/lms/staff/certificates` is now an **informational stub**
  — no issue form — so a bookmarked URL explains that certification moved to the
  admin instead of dead-ending or exposing a capability staff shouldn't have.
- **Added** "Certificates" to the **admin** sidebar under _Student operations_
  (`OwnerSidebar.tsx`).
- New admin page `/lms/admin/certificates`:
  - Issue a certificate: pick a student (course auto-fills from their enrolment,
    still editable), set a title (defaults to "Certificate of Completion"), and
    optionally paste a **link** to the certificate file (Google Drive / Canva /
    any URL — no file uploads, consistent with the rest of the SaaS).
  - Lists every issued certificate; each can be **revoked**.
  - Success toast on issue; matches the existing admin theme.
- Backend (`OwnerAdminController`): `certificates` (list + form data),
  `issueCertificate`, `revokeCertificate`. All tenant-scoped via `ownerContext()`
  — a student/course id from another institute is rejected. Issuing also creates
  an in-app `LmsNotification` for the student ("You earned a certificate").
- Students already see their certificates via `GET /api/frontend/lms/certificates`
  (`StudentProfileController::certificates`) — unchanged, and the notification now
  points them to it.

Data model is unchanged: `lms_certificates` (student_id, course_id, title,
file_url, issued_at), `TenantAware`.

## The right longer-term plan (v2+ — earned, not just granted)
Layer these on when there's time after launch; none block go-live.

1. **Eligibility signal.** The domain already defines "module complete = graded
   task ≥ 70%" and "course progress = completed modules / total". Surface a
   per-student **completion %** on the admin issue form and flag students at 100%
   as _eligible_. Keeps manual control but removes guesswork.
2. **One-click issue for eligible students.** From the flagged list, issue with a
   pre-filled title ("Certificate of Completion — {course}") without hunting for
   the student.
3. **Auto-generated certificate file.** Replace the pasted link with a generated
   PDF: institute logo + colors (branding already stored on the tenant), student
   name, course, date, and a unique verify code. Render server-side; store on the
   public disk like logos/covers. This is the biggest lift — do it after v1 proves
   the flow.
4. **Public verification.** A `/verify/{code}` page that confirms a certificate is
   genuine and shows the institute, student, course, and issue date. Turns the
   certificate into something an employer can trust.
5. **Revocation is already supported** — keep it, and have the verify page report a
   revoked certificate as invalid.

## Notes / follow-ups
- The **staff** certificate API routes (`/api/frontend/lms/staff/certificates`) are
  no longer used by any UI. They're left in place for now (not a broken UI link);
  optionally gate or remove the staff **POST** in a later hardening pass so the
  capability is admin-only at the API layer too, not just in the UI.
- Certificate `file_url` is validated as an http(s) URL on issue.
