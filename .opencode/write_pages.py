import os

base = "jorsas-tech-v2/src/app/lms/staff"

files = {}

files["tasks/page.tsx"] = '''"use client";

import { useEffect, useState } from "react";
import { STAFF_API, api } from "../../../../lib/api";

type Task = {
  id: number;
  course_id: number;
  teacher_id: number;
  title: string;
  description?: string;
  instructions?: string;
  due_at?: string;
  submission_type: string;
  submissions_count?: number;
  created_at: string;
};

type Submission = {
  id: number;
  task_id: number;
  student_id: number;
  student?: { id: number; first_name?: string; last_name?: string; email?: string };
  content?: string;
  file_url?: string;
  score?: number;
  feedback?: string;
  status?: string;
  graded_at?: string;
};

export default function StaffTasksPage() {
  const [tasks, setTasks] = useState<Task[]>([]);
  const [selectedTask, setSelectedTask] = useState<Task | null>(null);
  const [submissions, setSubmissions] = useState<Submission[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [courseId, setCourseId] = useState("");
  const [dueAt, setDueAt] = useState("");
  const [submissionType, setSubmissionType] = useState("link");
  const [instructions, setInstructions] = useState("");
  const [gradingId, setGradingId] = useState<number | null>(null);
  const [gradeScore, setGradeScore] = useState("");
  const [gradeFeedback, setGradeFeedback] = useState("");

  const token = typeof window !== "undefined" ? localStorage.getItem("lms_staff_token") ?? "" : "";

  async function loadTasks() {
    const res = await fetch(STAFF_API.tasks, { headers: { Authorization: `Bearer ${token}` } });
    const data = await res.json();
    setTasks(Array.isArray(data) ? data : []);
    setLoading(false);
  }

  useEffect(() => { if (token) loadTasks(); }, [token]);

  async function viewTask(task: Task) {
    setSelectedTask(task);
    const res = await fetch(STAFF_API.task(task.id), { headers: { Authorization: `Bearer ${token}` } });
    const data = await res.json();
    setSubmissions(Array.isArray(data?.submissions) ? data.submissions : []);
  }

  async function createTask() {
    setCreating(true);
    await fetch(api("/api/frontend/lms/staff/tasks"), {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({ course_id: Number(courseId), title, description: description || undefined, instructions: instructions || undefined, due_at: dueAt || undefined, submission_type: submissionType }),
    });
    setCreating(false);
    setTitle(""); setDescription(""); setCourseId(""); setDueAt(""); setInstructions("");
    await loadTasks();
  }

  async function grade(submissionId: number) {
    if (!selectedTask) return;
    setGradingId(submissionId);
    await fetch(api(`/api/frontend/lms/staff/tasks/${selectedTask.id}/submissions/${submissionId}/grade`), {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({ score: Number(gradeScore), feedback: gradeFeedback || null }),
    });
    setGradingId(null);
    setGradeScore(""); setGradeFeedback("");
    await viewTask(selectedTask);
  }

  return (
    <section>
      <h1 className="text-2xl font-bold">Tasks</h1>
      <p className="text-sm text-white/70">Create tasks, view submissions, and grade student work.</p>

      <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_1.5fr]">
        <div className="space-y-4">
          <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
            <h3 className="font-semibold">Create Task</h3>
            <div className="mt-2 grid gap-2">
              <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Task title" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
              <input value={courseId} onChange={(e) => setCourseId(e.target.value)} placeholder="Course ID" type="number" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
              <textarea value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Description" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" rows={2} />
              <textarea value={instructions} onChange={(e) => setInstructions(e.target.value)} placeholder="Instructions" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" rows={3} />
              <input value={dueAt} onChange={(e) => setDueAt(e.target.value)} type="datetime-local" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
              <select value={submissionType} onChange={(e) => setSubmissionType(e.target.value)} className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm">
                <option value="link">Link</option>
                <option value="file_upload">File Upload</option>
              </select>
              <button onClick={createTask} disabled={creating} className="rounded bg-white px-3 py-2 text-sm text-black disabled:opacity-60">
                {creating ? <span className="inline-flex items-center gap-2"><span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" /> Creating</span> : "Create Task"}
              </button>
            </div>
          </div>

          <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
            <h3 className="font-semibold">Task List</h3>
            {loading ? (
              <p className="mt-2 text-sm text-white/60">Loading...</p>
            ) : tasks.length === 0 ? (
              <p className="mt-2 text-sm text-white/60">No tasks yet.</p>
            ) : (
              <div className="mt-2 space-y-2">
                {tasks.map((t) => (
                  <button key={t.id} onClick={() => viewTask(t)} className={`w-full rounded-lg border px-3 py-2 text-left text-sm transition ${selectedTask?.id === t.id ? "border-white/30 bg-white/10" : "border-white/10 bg-white/[0.02] hover:bg-white/5"}`}>
                    <p className="font-medium">{t.title}</p>
                    <p className="text-xs text-white/60">{t.submissions_count ?? 0} submissions &middot; {t.submission_type}</p>
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          {selectedTask ? (
            <>
              <h3 className="font-semibold">{selectedTask.title}</h3>
              <p className="mt-1 text-xs text-white/60">Due: {selectedTask.due_at ? new Date(selectedTask.due_at).toLocaleString() : "No deadline"}</p>
              {selectedTask.description ? <p className="mt-2 text-sm text-white/80">{selectedTask.description}</p> : null}

              <h4 className="mt-4 font-semibold text-sm">Submissions ({submissions.length})</h4>
              {submissions.length === 0 ? (
                <p className="mt-2 text-sm text-white/60">No submissions yet.</p>
              ) : (
                <div className="mt-2 space-y-3">
                  {submissions.map((s) => (
                    <div key={s.id} className="rounded-lg border border-white/10 bg-black/20 p-3">
                      <p className="text-sm font-medium">{s.student?.first_name ?? "Student"} {s.student?.last_name ?? `#${s.student_id}`}</p>
                      {s.content ? <p className="mt-1 text-xs text-white/70">Content: {s.content}</p> : null}
                      {s.file_url ? <a href={s.file_url} target="_blank" rel="noreferrer" className="mt-1 inline-block text-xs text-blue-400 underline">View file</a> : null}
                      {s.score !== null && s.score !== undefined ? (
                        <p className="mt-1 text-xs text-white/80">Score: {s.score}{s.feedback ? ` Feedback: ${s.feedback}` : ""}</p>
                      ) : (
                        <div className="mt-2 flex gap-2">
                          <input value={gradeScore} onChange={(e) => setGradeScore(e.target.value)} placeholder="Score (0-100)" type="number" className="w-24 rounded border border-white/20 bg-black/30 px-2 py-1 text-xs" />
                          <input value={gradeFeedback} onChange={(e) => setGradeFeedback(e.target.value)} placeholder="Feedback" className="flex-1 rounded border border-white/20 bg-black/30 px-2 py-1 text-xs" />
                          <button onClick={() => grade(s.id)} disabled={gradingId === s.id} className="rounded bg-white px-2 py-1 text-xs text-black disabled:opacity-60">
                            {gradingId === s.id ? <span className="inline-flex items-center gap-1"><span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent" /></span> : "Grade"}
                          </button>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </>
          ) : (
            <p className="text-sm text-white/60">Select a task to view submissions.</p>
          )}
        </div>
      </div>
    </section>
  );
}
'''

files["materials/page.tsx"] = '''"use client";

import { useEffect, useState } from "react";
import { STAFF_API } from "../../../../lib/api";

type Material = {
  id: number;
  course_id: number;
  title: string;
  type: string;
  file_url: string;
  session_id?: number | null;
  created_at: string;
};

export default function StaffMaterialsPage() {
  const [materials, setMaterials] = useState<Material[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [title, setTitle] = useState("");
  const [type, setType] = useState("pdf");
  const [fileUrl, setFileUrl] = useState("");
  const [courseId, setCourseId] = useState("");
  const [deleting, setDeleting] = useState<number | null>(null);

  const token = typeof window !== "undefined" ? localStorage.getItem("lms_staff_token") ?? "" : "";

  async function load() {
    const res = await fetch(STAFF_API.materials, { headers: { Authorization: `Bearer ${token}` } });
    const data = await res.json();
    setMaterials(Array.isArray(data) ? data : []);
    setLoading(false);
  }

  useEffect(() => { if (token) load(); }, [token]);

  async function createMaterial() {
    setCreating(true);
    await fetch(STAFF_API.materials, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({ course_id: Number(courseId), title, type, file_url: fileUrl }),
    });
    setCreating(false);
    setTitle(""); setType("pdf"); setFileUrl(""); setCourseId("");
    await load();
  }

  async function remove(id: number) {
    if (!confirm("Delete this material?")) return;
    setDeleting(id);
    await fetch(STAFF_API.material(id), { method: "DELETE", headers: { Authorization: `Bearer ${token}` } });
    setDeleting(null);
    await load();
  }

  return (
    <section>
      <h1 className="text-2xl font-bold">Materials</h1>
      <p className="text-sm text-white/70">Upload and manage learning materials per course.</p>

      <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_1.5fr]">
        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Upload Material</h3>
          <div className="mt-2 grid gap-2">
            <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Material title" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={courseId} onChange={(e) => setCourseId(e.target.value)} placeholder="Course ID" type="number" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <select value={type} onChange={(e) => setType(e.target.value)} className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm">
              <option value="pdf">PDF</option>
              <option value="doc">Document</option>
              <option value="video">Video</option>
              <option value="link">Link</option>
              <option value="other">Other</option>
            </select>
            <input value={fileUrl} onChange={(e) => setFileUrl(e.target.value)} placeholder="File URL" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <button onClick={createMaterial} disabled={creating} className="rounded bg-white px-3 py-2 text-sm text-black disabled:opacity-60">
              {creating ? <span className="inline-flex items-center gap-2"><span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" /> Uploading</span> : "Upload"}
            </button>
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Materials</h3>
          {loading ? (
            <p className="mt-2 text-sm text-white/60">Loading...</p>
          ) : materials.length === 0 ? (
            <p className="mt-2 text-sm text-white/60">No materials uploaded yet.</p>
          ) : (
            <div className="mt-2 space-y-2">
              {materials.map((m) => (
                <div key={m.id} className="rounded-lg border border-white/10 bg-black/20 p-3">
                  <div className="flex items-start justify-between">
                    <div>
                      <p className="font-medium text-sm">{m.title}</p>
                      <p className="text-xs text-white/60">Type: {m.type} &middot; Course ID: {m.course_id}</p>
                      <a href={m.file_url} target="_blank" rel="noreferrer" className="mt-1 inline-block text-xs text-blue-400 underline">Open material</a>
                    </div>
                    <button onClick={() => remove(m.id)} disabled={deleting === m.id} className="text-xs text-red-400 underline disabled:opacity-40 shrink-0 ml-2">
                      {deleting === m.id ? "Deleting..." : "Delete"}
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
'''

files["announcements/page.tsx"] = '''"use client";

import { useEffect, useState } from "react";
import { STAFF_API } from "../../../../lib/api";

type Announcement = {
  id: number;
  batch_id: number;
  title: string;
  body?: string;
  is_published: boolean;
  batch?: { id: number; name: string };
  created_at: string;
};

export default function StaffAnnouncementsPage() {
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [batchId, setBatchId] = useState("");
  const [deleting, setDeleting] = useState<number | null>(null);

  const token = typeof window !== "undefined" ? localStorage.getItem("lms_staff_token") ?? "" : "";

  async function load() {
    const res = await fetch(STAFF_API.announcements, { headers: { Authorization: `Bearer ${token}` } });
    const data = await res.json();
    setAnnouncements(Array.isArray(data) ? data : []);
    setLoading(false);
  }

  useEffect(() => { if (token) load(); }, [token]);

  async function createAnnouncement() {
    setCreating(true);
    await fetch(STAFF_API.announcements, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({ batch_id: Number(batchId), title, body: body || undefined }),
    });
    setCreating(false);
    setTitle(""); setBody(""); setBatchId("");
    await load();
  }

  async function remove(id: number) {
    if (!confirm("Delete this announcement?")) return;
    setDeleting(id);
    await fetch(STAFF_API.announcement(id), { method: "DELETE", headers: { Authorization: `Bearer ${token}` } });
    setDeleting(null);
    await load();
  }

  return (
    <section>
      <h1 className="text-2xl font-bold">Announcements</h1>
      <p className="text-sm text-white/70">Post and manage announcements for your batches.</p>

      <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_1.5fr]">
        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">New Announcement</h3>
          <div className="mt-2 grid gap-2">
            <input value={batchId} onChange={(e) => setBatchId(e.target.value)} placeholder="Batch ID" type="number" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Announcement title" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="Announcement body" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" rows={4} />
            <button onClick={createAnnouncement} disabled={creating} className="rounded bg-white px-3 py-2 text-sm text-black disabled:opacity-60">
              {creating ? <span className="inline-flex items-center gap-2"><span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" /> Posting</span> : "Post Announcement"}
            </button>
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Posted Announcements</h3>
          {loading ? (
            <p className="mt-2 text-sm text-white/60">Loading...</p>
          ) : announcements.length === 0 ? (
            <p className="mt-2 text-sm text-white/60">No announcements yet.</p>
          ) : (
            <div className="mt-2 space-y-3">
              {announcements.map((a) => (
                <div key={a.id} className="rounded-lg border border-white/10 bg-black/20 p-3">
                  <div className="flex items-start justify-between">
                    <div>
                      <p className="font-medium">{a.title}</p>
                      {a.body ? <p className="mt-1 text-xs text-white/70">{a.body}</p> : null}
                      <p className="mt-1 text-xs text-white/50">{a.batch?.name or ""} {new Date(a.created_at).toLocaleDateString()}</p>
                    </div>
                    <button onClick={() => remove(a.id)} disabled={deleting === a.id} className="text-xs text-red-400 underline disabled:opacity-40 shrink-0 ml-2">
                      {deleting === a.id ? "Deleting..." : "Delete"}
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
'''

files["attendance/page.tsx"] = '''"use client";

import { useEffect, useState } from "react";
import { STAFF_API } from "../../../../lib/api";

type AttendanceRecord = {
  id: number;
  classroom_id: number;
  student_id: number;
  total_seconds?: number;
  status?: string;
  first_joined_at?: string;
  calculated_at?: string;
  student?: { id: number; first_name?: string; last_name?: string; email?: string };
  classroom?: { id: number; title: string };
};

export default function StaffAttendancePage() {
  const [records, setRecords] = useState<AttendanceRecord[]>([]);
  const [loading, setLoading] = useState(true);

  const token = typeof window !== "undefined" ? localStorage.getItem("lms_staff_token") ?? "" : "";

  useEffect(() => {
    if (!token) return;
    fetch(STAFF_API.attendance, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.json())
      .then((data) => { setRecords(Array.isArray(data) ? data : []); setLoading(false); })
      .catch(() => setLoading(false));
  }, [token]);

  return (
    <section>
      <h1 className="text-2xl font-bold">Attendance</h1>
      <p className="text-sm text-white/70">View attendance records for your classrooms.</p>

      <div className="mt-4 rounded-lg border border-white/10 bg-white/[0.02] p-4">
        {loading ? (
          <p className="text-sm text-white/60">Loading...</p>
        ) : records.length === 0 ? (
          <p className="text-sm text-white/60">No attendance records yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-white/10 text-left text-xs text-white/60">
                  <th className="pb-2 pr-4">Student</th>
                  <th className="pb-2 pr-4">Classroom</th>
                  <th className="pb-2 pr-4">Status</th>
                  <th className="pb-2 pr-4">Duration</th>
                  <th className="pb-2">Date</th>
                </tr>
              </thead>
              <tbody>
                {records.map((r) => (
                  <tr key={r.id} className="border-b border-white/5">
                    <td className="py-2 pr-4">{r.student?.first_name ?? "Student"} {r.student?.last_name ?? ""}</td>
                    <td className="py-2 pr-4">{r.classroom?.title ?? `Classroom #${r.classroom_id}`}</td>
                    <td className="py-2 pr-4">
                      <span className={`rounded-full px-2 py-0.5 text-xs ${r.status === "present" ? "bg-green-500/20 text-green-400" : r.status === "late" ? "bg-yellow-500/20 text-yellow-400" : "bg-red-500/20 text-red-400"}`}>
                        {r.status ?? "unknown"}
                      </span>
                    </td>
                    <td className="py-2 pr-4">{r.total_seconds ? `${Math.round(r.total_seconds / 60)}m` : "-"}</td>
                    <td className="py-2">{r.first_joined_at ? new Date(r.first_joined_at).toLocaleDateString() : "-"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  );
}
'''

files["certificates/page.tsx"] = '''"use client";

import { useEffect, useState } from "react";
import { STAFF_API } from "../../../../lib/api";

type Certificate = {
  id: number;
  student_id: number;
  course_id: number;
  title: string;
  file_url?: string;
  issued_at: string;
  student?: { id: number; first_name?: string; last_name?: string; email?: string };
};

export default function StaffCertificatesPage() {
  const [certificates, setCertificates] = useState<Certificate[]>([]);
  const [loading, setLoading] = useState(true);
  const [issuing, setIssuing] = useState(false);
  const [studentId, setStudentId] = useState("");
  const [courseId, setCourseId] = useState("");
  const [title, setTitle] = useState("");
  const [fileUrl, setFileUrl] = useState("");

  const token = typeof window !== "undefined" ? localStorage.getItem("lms_staff_token") ?? "" : "";

  async function load() {
    const res = await fetch(STAFF_API.certificates, { headers: { Authorization: `Bearer ${token}` } });
    const data = await res.json();
    setCertificates(Array.isArray(data) ? data : []);
    setLoading(false);
  }

  useEffect(() => { if (token) load(); }, [token]);

  async function issueCertificate() {
    setIssuing(true);
    await fetch(STAFF_API.certificates, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({ student_id: Number(studentId), course_id: Number(courseId), title, file_url: fileUrl || undefined }),
    });
    setIssuing(false);
    setStudentId(""); setCourseId(""); setTitle(""); setFileUrl("");
    await load();
  }

  return (
    <section>
      <h1 className="text-2xl font-bold">Certificates</h1>
      <p className="text-sm text-white/70">View eligibility and issue certificates to students.</p>

      <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_1.5fr]">
        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Issue Certificate</h3>
          <div className="mt-2 grid gap-2">
            <input value={studentId} onChange={(e) => setStudentId(e.target.value)} placeholder="Student ID" type="number" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={courseId} onChange={(e) => setCourseId(e.target.value)} placeholder="Course ID" type="number" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Certificate title" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={fileUrl} onChange={(e) => setFileUrl(e.target.value)} placeholder="Certificate file URL (optional)" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <button onClick={issueCertificate} disabled={issuing} className="rounded bg-white px-3 py-2 text-sm text-black disabled:opacity-60">
              {issuing ? <span className="inline-flex items-center gap-2"><span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" /> Issuing</span> : "Issue Certificate"}
            </button>
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Issued Certificates</h3>
          {loading ? (
            <p className="mt-2 text-sm text-white/60">Loading...</p>
          ) : certificates.length === 0 ? (
            <p className="mt-2 text-sm text-white/60">No certificates issued yet.</p>
          ) : (
            <div className="mt-2 space-y-2">
              {certificates.map((c) => (
                <div key={c.id} className="rounded-lg border border-white/10 bg-black/20 p-3">
                  <p className="font-medium text-sm">{c.title}</p>
                  <p className="text-xs text-white/60">{c.student?.first_name ?? "Student"} {c.student?.last_name ?? ""} &middot; {new Date(c.issued_at).toLocaleDateString()}</p>
                  {c.file_url ? <a href={c.file_url} target="_blank" rel="noreferrer" className="mt-1 inline-block text-xs text-blue-400 underline">View certificate</a> : null}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
'''

files["reports/page.tsx"] = '''"use client";

import { useEffect, useState } from "react";
import { STAFF_API } from "../../../../lib/api";

type ReportsData = {
  stats: {
    total_students: number;
    total_classes: number;
    total_tasks: number;
    total_submissions: number;
    graded_submissions: number;
  };
  recent_classes: { id: number; title: string; starts_at: string }[];
};

export default function StaffReportsPage() {
  const [data, setData] = useState<ReportsData | null>(null);
  const [loading, setLoading] = useState(true);

  const token = typeof window !== "undefined" ? localStorage.getItem("lms_staff_token") ?? "" : "";

  useEffect(() => {
    if (!token) return;
    fetch(STAFF_API.reports, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.json())
      .then((d) => { setData(d); setLoading(false); })
      .catch(() => setLoading(false));
  }, [token]);

  if (loading) return <section><h1 className="text-2xl font-bold">Reports</h1><p className="mt-2 text-sm text-white/60">Loading...</p></section>;

  const stats = data?.stats;

  return (
    <section>
      <h1 className="text-2xl font-bold">Reports</h1>
      <p className="text-sm text-white/70">Attendance, task, and engagement analytics.</p>

      <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {[
          { label: "Total Students", value: stats?.total_students ?? 0 },
          { label: "Total Classes", value: stats?.total_classes ?? 0 },
          { label: "Total Tasks", value: stats?.total_tasks ?? 0 },
          { label: "Submissions", value: stats?.total_submissions ?? 0 },
          { label: "Graded", value: stats?.graded_submissions ?? 0 },
          { label: "Grading Rate", value: (stats?.total_submissions && stats.total_submissions > 0) ? `${Math.round((stats.graded_submissions / stats.total_submissions) * 100)}%` : "0%" },
        ].map((s) => (
          <div key={s.label} className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
            <p className="text-xs text-white/60">{s.label}</p>
            <p className="mt-2 text-3xl font-semibold">{s.value}</p>
          </div>
        ))}
      </div>

      {data?.recent_classes && data.recent_classes.length > 0 && (
        <div className="mt-6 rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Recent Classes</h3>
          <div className="mt-2 space-y-2">
            {data.recent_classes.map((c) => (
              <div key={c.id} className="rounded-lg border border-white/10 bg-black/20 p-3">
                <p className="text-sm font-medium">{c.title}</p>
                <p className="text-xs text-white/60">{new Date(c.starts_at).toLocaleString()}</p>
              </div>
            ))}
          </div>
        </div>
      )}
    </section>
  );
}
'''

files["profile/page.tsx"] = '''"use client";

import { useEffect, useState } from "react";
import { STAFF_API } from "../../../../lib/api";

type Profile = {
  id: number;
  name: string;
  email: string;
  phone?: string;
  profile_photo_url?: string;
};

export default function StaffProfilePage() {
  const [profile, setProfile] = useState<Profile | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [changingPassword, setChangingPassword] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [profilePhotoUrl, setProfilePhotoUrl] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const token = typeof window !== "undefined" ? localStorage.getItem("lms_staff_token") ?? "" : "";

  useEffect(() => {
    if (!token) return;
    fetch(STAFF_API.profile, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.json())
      .then((data) => {
        setProfile(data);
        setName(data.name ?? "");
        setPhone(data.phone ?? "");
        setProfilePhotoUrl(data.profile_photo_url ?? "");
        setLoading(false);
      })
      .catch(() => setLoading(false));
  }, [token]);

  async function saveProfile() {
    setSaving(true);
    setMessage(""); setError("");
    const res = await fetch(STAFF_API.profile, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({ name, phone: phone || undefined, profile_photo_url: profilePhotoUrl || undefined }),
    });
    const data = await res.json();
    if (res.ok) { setMessage("Profile updated."); setProfile(data.teacher); }
    else { setError(data.message ?? "Failed to update profile."); }
    setSaving(false);
  }

  async function changePassword() {
    setChangingPassword(true);
    setMessage(""); setError("");
    const res = await fetch(STAFF_API.changePassword, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
    });
    const data = await res.json();
    if (res.ok) { setMessage("Password changed."); setCurrentPassword(""); setNewPassword(""); }
    else { setError(data.message ?? "Failed to change password."); }
    setChangingPassword(false);
  }

  if (loading) return <section><h1 className="text-2xl font-bold">Profile</h1><p className="mt-2 text-sm text-white/60">Loading...</p></section>;

  return (
    <section>
      <h1 className="text-2xl font-bold">Profile</h1>
      <p className="text-sm text-white/70">Manage your account settings and password.</p>

      {message ? <p className="mt-3 text-sm text-green-400">{message}</p> : null}
      {error ? <p className="mt-3 text-sm text-red-400">{error}</p> : null}

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Account Details</h3>
          <div className="mt-2 grid gap-2">
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Full name" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={profile?.email ?? ""} disabled placeholder="Email" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm opacity-60" />
            <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Phone number" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={profilePhotoUrl} onChange={(e) => setProfilePhotoUrl(e.target.value)} placeholder="Profile photo URL" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <button onClick={saveProfile} disabled={saving} className="rounded bg-white px-3 py-2 text-sm text-black disabled:opacity-60">
              {saving ? <span className="inline-flex items-center gap-2"><span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" /> Saving</span> : "Save Profile"}
            </button>
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Change Password</h3>
          <div className="mt-2 grid gap-2">
            <input value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} type="password" placeholder="Current password" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={newPassword} onChange={(e) => setNewPassword(e.target.value)} type="password" placeholder="New password (min 8 chars)" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <button onClick={changePassword} disabled={changingPassword || !currentPassword || !newPassword} className="rounded bg-white px-3 py-2 text-sm text-black disabled:opacity-60">
              {changingPassword ? <span className="inline-flex items-center gap-2"><span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" /> Changing</span> : "Change Password"}
            </button>
          </div>
        </div>
      </div>
    </section>
  );
}
'''

files["timetable/page.tsx"] = '''"use client";

import { useEffect, useState } from "react";
import { STAFF_API } from "../../../../lib/api";

type Classroom = {
  id: number;
  course_id: number;
  teacher_id: number;
  title: string;
  meeting_url?: string;
  meeting_id?: string;
  starts_at: string;
  ends_at?: string;
  course?: { id: number; title: string };
};

export default function StaffTimetablePage() {
  const [classrooms, setClassrooms] = useState<Classroom[]>([]);
  const [loading, setLoading] = useState(true);
  const [title, setTitle] = useState("");
  const [startsAt, setStartsAt] = useState("");
  const [endsAt, setEndsAt] = useState("");
  const [meetingUrl, setMeetingUrl] = useState("");
  const [meetingId, setMeetingId] = useState("");
  const [courseId, setCourseId] = useState("");
  const [creating, setCreating] = useState(false);

  const token = typeof window !== "undefined" ? localStorage.getItem("lms_staff_token") ?? "" : "";

  async function load() {
    const res = await fetch(STAFF_API.classrooms, { headers: { Authorization: `Bearer ${token}` } });
    const data = await res.json();
    setClassrooms(Array.isArray(data) ? data : []);
    setLoading(false);
  }

  useEffect(() => { if (token) load(); }, [token]);

  async function createClassroom() {
    setCreating(true);
    const res = await fetch(STAFF_API.classrooms, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({
        course_id: Number(courseId),
        title,
        starts_at: startsAt,
        ends_at: endsAt || undefined,
        meeting_url: meetingUrl || undefined,
        meeting_id: meetingId || undefined,
      }),
    });
    if (res.ok) {
      setTitle(""); setStartsAt(""); setEndsAt(""); setMeetingUrl(""); setMeetingId(""); setCourseId("");
      await load();
    }
    setCreating(false);
  }

  return (
    <section>
      <h1 className="text-2xl font-bold">Timetable</h1>
      <p className="text-sm text-white/70">Create and manage your class sessions.</p>

      <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_1.5fr]">
        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Create Session</h3>
          <div className="mt-2 grid gap-2">
            <input value={courseId} onChange={(e) => setCourseId(e.target.value)} placeholder="Course ID" type="number" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Session title" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={startsAt} onChange={(e) => setStartsAt(e.target.value)} type="datetime-local" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={endsAt} onChange={(e) => setEndsAt(e.target.value)} type="datetime-local" placeholder="Ends at" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={meetingUrl} onChange={(e) => setMeetingUrl(e.target.value)} placeholder="Meeting URL (optional)" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <input value={meetingId} onChange={(e) => setMeetingId(e.target.value)} placeholder="Zoom Meeting ID (optional)" className="rounded border border-white/20 bg-black/30 px-3 py-2 text-sm" />
            <button onClick={createClassroom} disabled={creating} className="rounded bg-white px-3 py-2 text-sm text-black disabled:opacity-60">
              {creating ? <span className="inline-flex items-center gap-2"><span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" /> Creating</span> : "Create Session"}
            </button>
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-white/[0.02] p-4">
          <h3 className="font-semibold">Upcoming & Past Sessions</h3>
          {loading ? (
            <p className="mt-2 text-sm text-white/60">Loading...</p>
          ) : classrooms.length === 0 ? (
            <p className="mt-2 text-sm text-white/60">No sessions created yet.</p>
          ) : (
            <div className="mt-2 space-y-2">
              {classrooms.map((c) => (
                <div key={c.id} className="rounded-lg border border-white/10 bg-black/20 p-3">
                  <div className="flex items-start justify-between">
                    <div>
                      <p className="font-medium text-sm">{c.title}</p>
                      <p className="text-xs text-white/60">{c.course?.title ? `Course: ${c.course.title}` : `Course ID: ${c.course_id}`}</p>
                      <p className="text-xs text-white/50">{new Date(c.starts_at).toLocaleString()}{c.ends_at ? ` - ${new Date(c.ends_at).toLocaleString()}` : ""}</p>
                      {c.meeting_id ? <p className="text-xs text-green-400">Zoom: {c.meeting_id}</p> : null}
                    </div>
                    {c.meeting_url ? <a href={c.meeting_url} target="_blank" rel="noreferrer" className="text-xs text-blue-400 underline shrink-0 ml-2">Join</a> : null}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
'''

path = "jorsas-tech-v2/src/app/lms/staff"
for rel, content in files.items():
    full = os.path.join(path, rel)
    with open(full, "w") as f:
        f.write(content.lstrip("\n"))
    print(f"Written: {full} ({len(content)} bytes)")

print("\nDone! All pages written.")
