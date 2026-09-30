{{-- Task detail slide-over. Alpine component fetches /tasks/{id} (JSON) and lets
     authorized users change status, reassign, comment, and manage attachments. --}}
<div x-data="taskPanel()" x-show="open" x-cloak>
  <div class="overlay" :class="{ show: open }" @click="close()"></div>
  <div class="panel" :class="{ show: open }">
    <div class="panel-head">
      <div style="flex:1; margin-right:12px; min-width:0;">
        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
          <span class="mono" style="color:var(--ink-faint); font-size:11px;" x-text="'TASK-' + String(task.id || '').padStart(4, '0')"></span>
          <template x-if="task.project">
            <a :href="task.project_url" class="mono" style="color:var(--primary); font-size:11px; text-decoration:none; background:var(--primary-soft); padding:1px 6px; border-radius:4px;" x-text="'📁 ' + task.project"></a>
          </template>
        </div>
        <template x-if="editing">
          <input type="text" x-model="task.name" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:15px; font-weight:700; margin-top:4px; font-family:inherit; background:var(--surface);">
        </template>
        <template x-if="!editing">
          <h3 style="margin-top:4px; font-size:16px; word-break:break-word;" x-text="task.name"></h3>
        </template>
      </div>
      <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
        <template x-if="task.is_locked">
          <span class="badge b-blocked" style="font-size:10px;">Locked</span>
        </template>
        <template x-if="task.can_lock && !task.is_locked">
          <button type="button" class="btn btn-ghost" style="padding:4px 10px; font-size:12px;" @click="lockTask()">Lock</button>
        </template>
        <template x-if="task.can_lock && task.is_locked">
          <button type="button" class="btn btn-ghost" style="padding:4px 10px; font-size:12px;" @click="unlockTask()">Unlock</button>
        </template>
        <template x-if="task.can_manage || task.can_update_status">
          <button type="button" class="btn btn-ghost" style="padding:4px 10px; font-size:12px;" @click="editing = !editing" x-show="!task.is_locked" x-text="editing ? 'Cancel' : '✎ Edit'"></button>
        </template>
        <template x-if="task.can_manage">
          <button type="button" class="btn btn-ghost" style="padding:4px 10px; font-size:12px; color:var(--danger);" @click="deleteTask()" title="Delete Task">🗑 Delete</button>
        </template>
        <div class="panel-close" @click="close()">✕</div>
      </div>
    </div>

    <div class="panel-body">
      <!-- Blocker Banner if Blocked -->
      <template x-if="task.status === 'Blocked'">
        <div style="background:#fee2e2; border:1px solid #f87171; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
          <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
              <div style="font-weight:700; color:#991b1b; font-size:13px; display:flex; align-items:center; gap:6px;">
                <span>⚠️ TASK BLOCKED</span>
              </div>
              <div style="font-size:12.5px; color:#7f1d1d; margin-top:4px; line-height:1.4;" x-text="task.blocker_reason || 'A team member reported a blocker on this task.'"></div>
            </div>
            <template x-if="task.can_update_status">
              <button type="button" class="btn btn-accent" style="padding:4px 10px; font-size:11.5px;" @click="resolveBlocker()">Resolve Blocker</button>
            </template>
          </div>
        </div>
      </template>

      <!-- Lock Banner if Locked -->
      <template x-if="task.is_locked || task.agreement_locked">
        <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:10px 14px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
          <div style="font-size:12px; color:#166534; display:flex; align-items:center; gap:6px;">
            <span>🔒 <strong>TASK AGREEMENT LOCKED</strong></span>
            <span style="color:#15803d;" x-text="task.lock_reason ? '— ' + task.lock_reason : '— Agreed terms locked upon acceptance'"></span>
          </div>
          <template x-if="task.can_modify_locked">
            <span class="badge" style="background:#bbf7d0; color:#14532d; font-size:10px;">Admin Override Enabled</span>
          </template>
        </div>
      </template>

      <!-- Assignment Accept / Reject Action Banner.
           Shown ONLY while the signed-in user's own task_user pivot row is
           still 'pending' — accepting or rejecting hides it immediately. -->
      <template x-if="task.my_assignment && task.my_assignment.status === 'pending'">
        <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div>
              <div style="font-weight:700; color:#1e40af; font-size:13px;">📋 Assignment Response Required</div>
              <div style="font-size:12px; color:#1d4ed8; margin-top:2px;">You are assigned to this task. Please accept or reject below:</div>
            </div>
            <div style="display:flex; gap:8px;">
              <button type="button" class="btn btn-primary" style="padding:4px 12px; font-size:12px;" @click="acceptTask()">✓ Accept Task</button>
              <button type="button" class="btn btn-ghost" style="padding:4px 12px; font-size:12px; color:var(--danger); border-color:#fca5a5;" @click="showRejectModal = true">✕ Reject</button>
            </div>
          </div>
        </div>
      </template>

      <div class="field-row">
        <span class="k">Status</span>
        <span class="v">
          <template x-if="task.can_update_status || editing">
            <select
              x-model="task.status"
              @change="!editing && updateStatus()"
              style="border:1px solid var(--line); border-radius:6px; padding:4px 8px; font-size:12.5px; font-family:inherit; font-weight:700; color:inherit; background:var(--surface);"
            >
              <!-- Options come from the server: assigned members get the four
                   delivery statuses, managers additionally get Blocked. -->
              <template x-for="statusOption in (task.statuses || ['To Do', 'In Progress', 'In Review', 'Completed'])" :key="statusOption">
                <option :value="statusOption" x-text="statusOption"></option>
              </template>
            </select>
          </template>
          <template x-if="!task.can_update_status && !editing">
            <span class="badge" :class="task.status === 'Completed' || task.status === 'Done' ? 'b-active' : (task.status === 'In Progress' ? 'b-planning' : (task.status === 'Blocked' ? 'b-blocked' : 'b-risk'))" x-text="task.status"></span>
          </template>
          <template x-if="task.acceptance_blocked">
            <div style="font-size:11px; color:var(--danger); margin-top:4px;">Accept or reject your assignment before changing the status.</div>
          </template>
        </span>
      </div>

      <div class="field-row">
        <span class="k">Priority</span>
        <span class="v">
          <template x-if="task.can_manage || editing">
            <select
              x-model="task.priority"
              @change="!editing && savePriority()"
              style="border:1px solid var(--line); border-radius:6px; padding:4px 8px; font-size:12.5px; font-family:inherit; font-weight:600; background:var(--surface);"
            >
              <option value="High">High</option>
              <option value="Medium">Medium</option>
              <option value="Low">Low</option>
              <option value="Urgent">Urgent</option>
            </select>
          </template>
          <template x-if="!task.can_manage && !editing">
            <span class="priority" :class="'p-' + (task.priority || '').toLowerCase()" x-text="task.priority"></span>
          </template>
        </span>
      </div>

      <div class="field-row">
        <span class="k">Phase</span>
        <span class="v">
          <template x-if="editing && task.phases && task.phases.length">
            <select
              x-model="task.phase_id"
              style="border:1px solid var(--line); border-radius:6px; padding:4px 8px; font-size:12.5px; font-family:inherit; background:var(--surface);"
            >
              <template x-for="p in task.phases" :key="p.id">
                <option :value="p.id" x-text="p.name" :selected="p.id == task.phase_id"></option>
              </template>
            </select>
          </template>
          <template x-if="!editing || !task.phases || !task.phases.length">
            <span x-text="task.phase || '—'"></span>
          </template>
        </span>
      </div>

      <div class="field-row">
        <span class="k">Budget</span>
        <span class="v">
          <template x-if="editing">
            <input type="number" step="0.01" min="0" x-model="task.budget" placeholder="Budget in ETB" style="border:1px solid var(--line); border-radius:6px; padding:4px 8px; font-size:12.5px; font-family:inherit; background:var(--surface);">
          </template>
          <template x-if="!editing">
            <span style="font-weight:700; color:var(--ink);" x-text="task.budget ? 'ETB ' + Number(task.budget).toLocaleString() : 'ETB 0'"></span>
          </template>
        </span>
      </div>

      <template x-if="task.phase_budget">
        <div class="field-row">
          <span class="k">Phase funds</span>
          <span class="v" style="font-size:12px;">
            <span>Available for tasks: <strong style="color:var(--success);" x-text="'ETB ' + Number(task.phase_budget.remaining || 0).toLocaleString(undefined, {minimumFractionDigits: 2})"></strong></span>
          </span>
        </div>
      </template>

      <div class="field-row">
        <span class="k">Start Date</span>
        <span class="v">
          <template x-if="editing">
            <input type="date" x-model="task.start_date" style="border:1px solid var(--line); border-radius:6px; padding:4px 8px; font-size:12.5px; font-family:inherit; background:var(--surface);">
          </template>
          <template x-if="!editing">
            <span x-text="task.start_date_formatted || '—'"></span>
          </template>
        </span>
      </div>

      <div class="field-row">
        <span class="k">End Date</span>
        <span class="v">
          <template x-if="editing">
            <input type="date" x-model="task.end_date" style="border:1px solid var(--line); border-radius:6px; padding:4px 8px; font-size:12.5px; font-family:inherit; background:var(--surface);">
          </template>
          <template x-if="!editing">
            <span x-text="task.end_date_formatted || '—'"></span>
          </template>
        </span>
      </div>

      <div class="field-row">
        <span class="k">Collaborators</span>
        <span class="v" style="display:flex; flex-direction:column; align-items:stretch; gap:6px;">
          <template x-for="a in (task.assignees || [])" :key="a.id">
            <span style="display:flex; justify-content:space-between; gap:8px; align-items:center;">
              <span x-text="a.name"></span>
              <span style="display:flex; gap:5px; align-items:center;">
                <span class="badge" :class="a.status_slug === 'accepted' ? 'b-active' : (a.status_slug === 'rejected' ? 'b-blocked' : 'b-risk')" x-text="a.acceptance_status"></span>
                <template x-if="a.can_respond && a.status_slug === 'pending' && !task.is_locked">
                  <span style="display:flex; gap:4px;">
                    <button type="button" class="btn btn-ghost" style="padding:2px 6px; font-size:10px;" @click="respondAssignment(a, 'accepted')">Accept</button>
                    <button type="button" class="btn btn-ghost" style="padding:2px 6px; font-size:10px;" @click="showRejectModal = true">Reject</button>
                  </span>
                </template>
              </span>
            </span>
          </template>
          <template x-if="task.can_manage && !task.is_locked && task.assignable_users && task.assignable_users.length">
            <select multiple x-model="selectedCollaboratorIds" style="border:1px solid var(--line); border-radius:6px; padding:4px 8px; font-size:12px; background:var(--surface);">
              <template x-for="u in task.assignable_users" :key="u.id">
                <option :value="String(u.id)" x-text="u.name"></option>
              </template>
            </select>
          </template>
          <template x-if="task.can_manage && !task.is_locked">
            <button type="button" class="btn btn-ghost" style="padding:4px 8px; font-size:11px;" @click="saveCollaborators()">Save collaborators</button>
          </template>
        </span>
      </div>

      <div style="margin-top:18px;">
        <div class="stat-label" style="margin-bottom:8px;">Description</div>
        <template x-if="editing">
          <textarea x-model="task.description" placeholder="Add description..." style="width:100%; border:1px solid var(--line); border-radius:6px; padding:8px 10px; font-size:13px; font-family:inherit; background:var(--surface); min-height:80px;"></textarea>
        </template>
        <template x-if="!editing">
          <div
            style="font-size:13px; color:var(--ink-soft); line-height:1.6;"
            x-text="task.description || 'No description provided.'"
          ></div>
        </template>
      </div>

      <template x-if="editing">
        <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:8px;">
          <button type="button" class="btn btn-ghost" @click="editing = false">Cancel</button>
          <button type="button" class="btn btn-primary" @click="saveTaskChanges()">Save Changes</button>
        </div>
      </template>

      <div
        x-show="savedMessage"
        x-cloak
        style="margin-top:12px; font-size:12px; color:var(--success); font-weight:600;"
        x-text="savedMessage"
      ></div>

      <!-- Assigned Users Section (Requirement 3, 7, 8) -->
      <div style="margin-top:20px; border-top:1px solid var(--line); padding-top:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
          <div class="stat-label" style="margin:0;">Assigned Team Members</div>
          <span style="font-size:11.5px; color:var(--ink-soft);" x-text="(task.assignees ? task.assignees.length : 0) + ' assigned'"></span>
        </div>

        <template x-for="as in task.assignees" :key="as.id || as.user_id">
          <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 10px; background:var(--bg-subtle); border:1px solid var(--line); border-radius:6px; margin-bottom:6px;">
            <div style="display:flex; align-items:center; gap:8px;">
              <div style="width:26px; height:26px; border-radius:50%; background:var(--primary-soft); color:var(--primary); font-weight:700; font-size:11px; display:flex; align-items:center; justify-content:center;"
                x-text="(as.name || '?').split(' ').map(w => w[0]).join('')"></div>
              <div>
                <div style="font-size:13px; font-weight:600; color:var(--ink);" x-text="as.name"></div>
                <div style="font-size:11px; color:var(--ink-muted);" x-text="as.role_label || 'Assignee'"></div>
              </div>
            </div>
            <div style="text-align:right; display:flex; align-items:center; gap:8px;">
              <div>
                <span class="badge"
                  :class="as.acceptance_status === 'Accepted' ? 'b-active' : (as.acceptance_status === 'Rejected' ? 'b-blocked' : 'b-risk')"
                  style="font-size:10px;"
                  x-text="as.acceptance_status"></span>
                <template x-if="as.rejection_reason">
                  <div style="font-size:10.5px; color:var(--danger); margin-top:2px; max-width:180px;" x-text="'Reason: ' + as.rejection_reason"></div>
                </template>
              </div>
              <template x-if="task.can_manage && (!task.is_locked || task.can_modify_locked)">
                <button type="button" @click="removeAssignee(as.user_id)" title="Remove assignee" class="btn btn-ghost" style="padding:2px 6px; font-size:11px; color:var(--danger); border:none; line-height:1;">✕</button>
              </template>
            </div>
          </div>
        </template>

        <!-- Add Additional Assignee (if can_manage and not locked or admin) -->
        <template x-if="task.can_manage && (!task.is_locked || task.can_modify_locked)">
          <div style="display:flex; gap:8px; margin-top:8px; flex-wrap:wrap;">
            <select x-model="newAssigneeUserId" style="flex:1; min-width:160px; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:12.5px; font-family:inherit; background:var(--surface);">
              <option value="">— Add Assignee —</option>
              <template x-for="u in task.assignable_users" :key="u.id">
                <option :value="u.id" x-text="u.name"></option>
              </template>
            </select>
            <input type="text" x-model="newAssigneeRole" placeholder="Role (e.g. Tester, Frontend)" style="width:140px; border:1px solid var(--line); border-radius:6px; padding:6px 8px; font-size:12px; font-family:inherit; background:var(--surface);">
            <button type="button" class="btn btn-ghost" style="padding:6px 12px; font-size:12px;" @click="assignAdditionalUser()" :disabled="!newAssigneeUserId">+ Assign</button>
          </div>
        </template>
      </div>

      <!-- Cost / Payment Section (Requirement 2) -->
      <div style="margin-top:16px; padding:10px 12px; background:var(--bg-subtle); border:1px solid var(--line); border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
        <div>
          <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--ink-muted);">Task Expenditure / Cost</div>
          <div style="font-size:14px; font-weight:800; color:var(--ink); margin-top:2px;" x-text="'ETB ' + Number(task.total_cost || 0).toLocaleString()"></div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
          <template x-if="task.can_log_expense">
            <button type="button" class="btn btn-primary" style="padding:4px 10px; font-size:11px;" @click="showExpenseModal = true">+ Log Expense</button>
          </template>
          <a href="{{ route('payments.index') }}" class="btn btn-ghost" style="padding:4px 10px; font-size:11px;">View Payments →</a>
        </div>
      </div>

      <!-- Subtasks Section -->
      <div style="margin-top:20px; border-top:1px solid var(--line); padding-top:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
          <div class="stat-label" style="margin:0;">Subtasks</div>
          <span style="font-size:11.5px; color:var(--ink-soft);" x-text="taskTreeCount(task.subtasks) + ' nested task(s)'"></span>
        </div>

        <div x-html="renderTaskTree(task.subtasks)"></div>

        <div style="display:flex; gap:8px; margin-top:8px;">
          <input
            type="text"
            x-model="newSubtaskName"
            @keydown.enter="addSubtask()"
            :disabled="task.is_locked"
            placeholder="Add a new subtask..."
            style="flex:1; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:12.5px; font-family:inherit; background:var(--surface);"
          >
          <button type="button" class="btn btn-ghost" style="padding:6px 12px; font-size:12px;" @click="addSubtask()" :disabled="addingSubtask || task.is_locked">+ Add</button>
        </div>
      </div>

      <!-- File Attachments Section -->
      <div style="margin-top:20px; border-top:1px solid var(--line); padding-top:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
          <div class="stat-label" style="margin:0;">Attachments</div>
          <span style="font-size:11.5px; color:var(--ink-soft);" x-text="(task.attachments ? task.attachments.length : 0) + ' file(s)'"></span>
        </div>

        <template x-for="a in task.attachments" :key="a.id">
          <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:var(--bg-subtle); border:1px solid var(--line); border-radius:6px; margin-bottom:6px;">
            <div style="display:flex; align-items:center; gap:8px; overflow:hidden;">
              <span>📎</span>
              <div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                <a :href="'/attachments/' + a.id + '/download'" style="font-weight:600; font-size:13px; color:var(--accent); text-decoration:none;" x-text="a.file_name" target="_blank"></a>
                <div style="font-size:11px; color:var(--ink-muted);" x-text="'By ' + a.uploader + ' • ' + a.uploaded_at"></div>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
              <a :href="'/attachments/' + a.id + '/download'" class="btn btn-ghost" style="padding:3px 8px; font-size:11px;" title="Download File">⬇</a>
              <button type="button" class="btn btn-ghost" style="padding:3px 8px; font-size:11px; color:var(--danger);" @click="deleteAttachment(a.id)" title="Remove Attachment">✕</button>
            </div>
          </div>
        </template>

        <div style="margin-top:8px;">
          <label class="btn btn-ghost" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 12px; cursor:pointer; width:100%; justify-content:center; border:1px dashed var(--line);">
            <span>📁 Upload Attachment</span>
            <input type="file" @change="uploadFile($event)" style="display:none;" :disabled="uploadingFile">
          </label>
        </div>
      </div>

      <!-- Activity & Status Trail -->
      <div style="margin-top:20px; border-top:1px solid var(--line); padding-top:16px;" x-show="task.activity_logs && task.activity_logs.length">
        <div class="stat-label" style="margin-bottom:10px;">Activity Trail &amp; Remarks</div>
        <template x-for="l in task.activity_logs" :key="l.at + l.from + l.to + (l.remarks || '')">
          <div style="padding:6px 0; border-bottom:1px solid var(--line); font-size:12px;">
            <div style="display:flex; justify-content:space-between; color:var(--ink);">
              <span>
                <strong x-text="l.user"></strong>:
                <span class="badge" style="font-size:10px;" x-text="l.from"></span> →
                <span class="badge b-active" style="font-size:10px;" x-text="l.to"></span>
              </span>
              <span style="font-size:11px; color:var(--ink-muted);" x-text="l.at"></span>
            </div>
            <template x-if="l.remarks">
              <div style="font-size:11.5px; color:var(--ink-soft); margin-top:3px; font-style:italic;" x-text="'📝 ' + l.remarks"></div>
            </template>
          </div>
        </template>
      </div>

      <!-- Comments Section -->
      <div style="margin-top:20px; border-top:1px solid var(--line); padding-top:16px;">
        <div class="stat-label" style="margin-bottom:10px;">Comments</div>
        <template x-for="c in task.comments" :key="c.id || (c.user + c.at + c.text)">
          <div class="comment">
            <div
              class="avatar"
              x-text="(c.user || '?').split(' ').map(w => w[0]).join('')"
            ></div>
            <div class="txt">
              <div class="who">
                <span x-text="c.user"></span>
                <span class="when" x-text="c.at"></span>
              </div>
              <span x-text="c.text"></span>
            </div>
          </div>
        </template>
        <div
          x-show="!task.comments || !task.comments.length"
          style="font-size:12.5px; color:var(--ink-faint);"
        >
          No comments yet.
        </div>
        <div style="display:flex; gap:8px; margin-top:12px;">
          <input
            x-model="newComment"
            @keydown.enter="postComment()"
            placeholder="Write a comment…"
            style="flex:1; border:1px solid var(--line); border-radius:8px; padding:9px 11px; font-size:12.8px; font-family:inherit;"
          >
          <button
            class="btn btn-primary"
            style="padding:9px 14px;"
            @click="postComment()"
            :disabled="posting"
          >
            Send
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Rejection Modal Dialog -->
  <div x-show="showRejectModal" x-cloak style="position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99999; display:flex; align-items:center; justify-content:center; padding:16px;">
    <div class="card card-pad" style="max-width:440px; width:100%; background:var(--surface);" @click.away="showRejectModal = false">
      <h3 style="margin-top:0; color:var(--danger); font-size:15px;">Reject Task Assignment</h3>
      <p style="font-size:13px; color:var(--ink-soft); margin-bottom:12px;">Please provide the reason for rejecting this assignment so your manager can reassign or adjust the task:</p>
      <textarea x-model="rejectionReason" placeholder="Reason for rejection (e.g. Schedule conflict, requires backend skill, etc.)..." rows="3" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:8px 10px; font-size:13px; font-family:inherit;"></textarea>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
        <button type="button" class="btn btn-ghost" @click="showRejectModal = false">Cancel</button>
        <button type="button" class="btn btn-accent" style="background:var(--danger); border-color:var(--danger);" @click="submitRejection()" :disabled="!rejectionReason.trim()">Confirm Rejection</button>
      </div>
    </div>
  </div>

  <!-- Log Task Expenditure: assigned workers submit against the task/phase
       budget; the request enters the approval queue as Pending. -->
  <template x-if="task.can_log_expense">
    <div x-show="showExpenseModal" x-cloak style="position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:99999; display:flex; align-items:center; justify-content:center; padding:16px;">
      <div class="card card-pad" style="max-width:480px; width:100%; background:var(--surface);" @click.away="showExpenseModal = false">
        <h3 style="margin-top:0; font-size:15px;">Log Task Expenditure</h3>
        <p style="font-size:12.5px; color:var(--ink-soft); margin-bottom:12px;">
          Submitted expenses go to the Project Manager / Team Lead for approval, then to the Head of Office / Finance to confirm disbursement.
        </p>
        <template x-if="task.phase_budget">
          <div style="font-size:12px; background:var(--bg-subtle); border:1px solid var(--line); border-radius:6px; padding:8px 10px; margin-bottom:12px;">
            Phase funds available for new expenses:
            <strong style="color:var(--success);" x-text="'ETB ' + Number(task.phase_budget.expense_remaining || 0).toLocaleString(undefined, {minimumFractionDigits: 2})"></strong>
          </div>
        </template>
        <form method="POST" action="{{ route('payments.store') }}">
          @csrf
          <input type="hidden" name="task_id" :value="task.id">
          <input type="hidden" name="project_id" :value="task.project_id">
          <input type="hidden" name="phase_id" :value="task.phase_id">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-field">
              <label>Amount (ETB) <span style="color:var(--danger);">*</span></label>
              <input type="number" step="0.01" min="0.01" name="amount" required placeholder="e.g. 1500" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
            </div>
            <div class="form-field">
              <label>Expense Date <span style="color:var(--danger);">*</span></label>
              <input type="date" name="payment_date" required value="{{ now()->toDateString() }}" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
            </div>
          </div>
          <div class="form-field">
            <label>Paid To / Payee <span style="color:var(--danger);">*</span></label>
            <input type="text" name="recipient" required placeholder="e.g. Transport vendor, materials supplier" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
          </div>
          <div class="form-field">
            <label>Justification / Remarks</label>
            <textarea name="description" rows="2" placeholder="What this expense was for..." style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;"></textarea>
          </div>
          <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
            <button type="button" class="btn btn-ghost" @click="showExpenseModal = false">Cancel</button>
            <button type="submit" class="btn btn-primary">Submit for Approval</button>
          </div>
        </form>
      </div>
    </div>
  </template>
</div>

<script>
  function taskPanel() {
    return {
      open: false,
      editing: false,
      task: {},
      selectedCollaboratorIds: [],
      dirty: false,
      newComment: '',
      posting: false,
      newSubtaskName: '',
      addingSubtask: false,
      uploadingFile: false,
      savedMessage: '',
      showRejectModal: false,
      showExpenseModal: false,
      rejectionReason: '',
      newAssigneeUserId: '',
      newAssigneeRole: '',

      csrf() {
        return document.querySelector('meta[name="csrf-token"]').content;
      },

      async show(taskId, startInEditMode = false) {
        const res = await fetch(`/tasks/${taskId}`);
        if (!res.ok) {
          console.error('Failed to load task:', res.status, res.statusText);
          return;
        }
        this.task = await res.json();
        this.selectedCollaboratorIds = (this.task.assignees || []).map(a => String(a.user_id));
        this.open = true;
        this.editing = startInEditMode;
        this.dirty = false;
        this.showRejectModal = false;
        this.showExpenseModal = false;
        this.rejectionReason = '';

        const fileInput = document.getElementById('file-input');
        if (fileInput) {
          fileInput.value = ''; // reset file input
        }
      },

      close() {
        this.open = false;
        // Kanban / My Tasks are rendered server-side, so if status or
        // assignee changed while the panel was open, refresh to match.
        if (this.dirty) {
          window.location.reload();
        }
      },

      flash(msg) {
        this.savedMessage = msg;
        setTimeout(() => {
          this.savedMessage = '';
        }, 2500);
      },

      async deleteTask() {
        const confirmed = await confirmDialog({
          title: `Delete task '${this.task.name}'?`,
          text: 'This action is permanent and cannot be undone.',
          confirmText: 'Yes, delete it',
        });

        if (!confirmed) {
          return;
        }

        try {
          const res = await fetch(`/tasks/${this.task.id}`, {
            method: 'DELETE',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            }
          });

          if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alertDialog({ text: err.message || 'Failed to delete task.' });
            return;
          }

          this.open = false;
          window.location.reload();
        } catch (e) {
          console.error('Delete error:', e);
          alertDialog({ text: 'An error occurred while deleting the task.' });
        }
      },

      async saveTaskChanges() {
        try {
          const res = await fetch(`/tasks/${this.task.id}`, {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            },
            body: JSON.stringify({
              task_name: this.task.name,
              status: this.task.status,
              priority: this.task.priority,
              phase_id: this.task.phase_id ? Number(this.task.phase_id) : null,
              assigned_to: this.task.assignee_name || this.task.assignee_id || null,
              budget: this.task.budget ? Number(this.task.budget) : 0,
              start_date: this.task.start_date || null,
              end_date: this.task.end_date || null,
              description: this.task.description || null
            })
          });

          if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            console.error('Failed to update task:', res.status, err);
            alertDialog({ text: err.error || err.message || 'Failed to save task changes.' });
            return;
          }

          const data = await res.json();
          if (data.task) {
            this.task.name = data.task.task_name;
            this.task.status = data.task.status;
            this.task.priority = data.task.priority;
            this.task.phase_id = data.task.phase_id;
            this.task.assignee_id = data.task.assigned_to;
            this.task.assignee = data.task.assignee ? data.task.assignee.full_name : null;
            this.task.assignee_name = data.task.assignee ? data.task.assignee.full_name : null;
            this.task.budget = data.task.budget;
            this.task.start_date = data.task.start_date;
            this.task.end_date = data.task.end_date;
            this.task.start_date_formatted = data.task.start_date_formatted;
            this.task.end_date_formatted = data.task.end_date_formatted;
            this.task.phase = data.task.phase ? data.task.phase.phase_name : null;
            this.task.description = data.task.description;
            // Keep the project context in sync if the task moved to another
            // project's phase — the panel is shared across Projects / My Tasks.
            if (data.task.phase && data.task.phase.project) {
              this.task.project = data.task.phase.project_name;
              this.task.project_id = data.task.phase.project_id;
              this.task.project_url = '/projects/' + data.task.phase.project_id;
            }
          }

          this.editing = false;
          this.flash('Task updated successfully');
          this.dirty = true;
        } catch (e) {
          console.error('Save changes error:', e);
          alertDialog({ text: 'An error occurred while saving task changes.' });
        }
      },

      taskTreeCount(nodes) {
        return (nodes || []).reduce((total, node) => total + 1 + this.taskTreeCount(node.children), 0);
      },

      escapeTaskText(value) {
        return String(value || '').replace(/[&<>'"]/g, character => ({
          '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[character]));
      },

      renderTaskTree(nodes, depth = 0) {
        return (nodes || []).map(node => `
          <div style="margin-left:${depth * 14}px; display:flex; align-items:center; gap:8px; padding:6px 10px; background:var(--bg-subtle); border:1px solid var(--line); border-radius:6px; margin-bottom:6px;">
            <input type="checkbox" ${node.is_completed ? 'checked' : ''} ${this.task.is_locked ? 'disabled' : ''} onchange="window.toggleTaskSubtask(${node.id})" style="accent-color:var(--accent); cursor:pointer;">
            <span style="flex:1; font-size:13px; ${node.is_completed ? 'text-decoration:line-through; color:var(--ink-muted);' : 'color:var(--ink);'}">${this.escapeTaskText(node.name)}</span>
            <span class="badge ${node.is_completed ? 'b-active' : 'b-risk'}" style="font-size:10px;">${node.is_completed ? 'Done' : 'Pending'}</span>
            <button type="button" ${this.task.is_locked ? 'disabled' : ''} onclick="window.deleteTaskSubtask(${node.id})" title="Delete subtask" style="border:none; background:transparent; color:var(--danger); cursor:pointer; font-size:12px; line-height:1;">✕</button>
          </div>${this.renderTaskTree(node.children, depth + 1)}
        `).join('');
      },

      async saveCollaborators() {
        const res = await fetch(`/tasks/${this.task.id}/assign`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
          body: JSON.stringify({ assignees: this.selectedCollaboratorIds })
        });
        if (!res.ok) {
          alertDialog({ text: 'Failed to save collaborators.' });
          return;
        }
        const data = await res.json();
        this.task.assignees = data.assignees || [];
        this.selectedCollaboratorIds = (this.task.assignees || []).map(a => String(a.user_id));
        this.task.assignee_id = data.assignee_id || null;
        this.task.assignee = data.assignee || null;
        this.task.assignee_name = data.assignee || null;
        this.flash('Collaborators updated');
        this.dirty = true;
      },

      /**
       * Apply an accept/reject payload straight onto the panel state so the
       * response banner disappears and the collaborator badge flips to
       * Accepted/Rejected without waiting for a reload.
       */
      applyAssignmentPayload(data) {
        if (Array.isArray(data.assignees)) {
          this.task.assignees = data.assignees;
        }
        if ('my_assignment' in data) {
          this.task.my_assignment = data.my_assignment;
        }
        if (typeof data.requires_acceptance === 'boolean') {
          this.task.requires_acceptance = data.requires_acceptance;
        }
        if (typeof data.task_status === 'string') {
          this.task.status = data.task_status;
        }

        const pending = !!(this.task.my_assignment && this.task.my_assignment.status === 'pending');
        this.task.acceptance_blocked = pending;
        this.task.can_accept = pending;
        this.task.can_reject = pending;
      },

      async respondAssignment(assignment, status) {
        try {
          const res = await fetch(`/tasks/assignments/${assignment.id}/respond`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
            body: JSON.stringify({ status })
          });

          if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alertDialog({ text: err.message || 'Failed to respond to the assignment.' });
            return;
          }

          const data = await res.json().catch(() => ({}));
          assignment.status = data.status || status;
          this.applyAssignmentPayload(data);
          this.flash(`Assignment ${status}`);
          this.dirty = true;
          await this.show(this.task.id);
        } catch (e) {
          console.error('Assignment response error:', e);
        }
      },

      async lockTask() {
        const res = await fetch(`/tasks/${this.task.id}/lock`, {
          method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() }
        });
        if (res.ok) {
          this.task.is_locked = true;
          this.editing = false;
          this.task.can_update_status = false;
          this.flash('Task locked');
        }
      },

      async unlockTask() {
        const res = await fetch(`/tasks/${this.task.id}/unlock`, {
          method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() }
        });
        if (res.ok) {
          this.task.is_locked = false;
          this.task.can_update_status = true;
          this.flash('Task unlocked');
        }
      },

      async updateStatus() {
        if (this.task.status === 'Blocked' && !this.task.blocker_reason) {
          const reason = prompt('Please enter the reason why this task is blocked:');
          this.task.blocker_reason = reason || 'Blocker reported';
        }

        try {
          const res = await fetch(`/tasks/${this.task.id}/status`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            },
            body: JSON.stringify({
              status: this.task.status,
              blocker_reason: this.task.blocker_reason
            })
          });

          if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alertDialog({ text: err.message || 'This status change was not allowed.' });
            // Re-sync with the server so the select reflects the real status.
            await this.show(this.task.id);
            return;
          }

          const data = await res.json();
          this.task.status = data.status;
          this.task.blocker_reason = data.blocker_reason;
          this.flash('Status updated');
          this.dirty = true;
        } catch (e) {
          console.error('Status update error:', e);
        }
      },

      async resolveBlocker() {
        this.task.status = 'In Progress';
        this.task.blocker_reason = null;
        await this.updateStatus();
        this.flash('Blocker resolved');
      },

      async savePriority() {
        try {
          const res = await fetch(`/tasks/${this.task.id}`, {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            },
            body: JSON.stringify({ priority: this.task.priority })
          });
          if (res.ok) {
            this.flash('Priority updated');
            this.dirty = true;
          }
        } catch(e) {
          console.error(e);
        }
      },

      async postComment() {
        if (!this.newComment.trim() || this.posting) {
          return;
        }
        this.posting = true;
        try {
          const res = await fetch(`/tasks/${this.task.id}/comments`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            },
            body: JSON.stringify({
              comment_text: this.newComment
            })
          });
          if (!res.ok) {
            console.error(
              'Failed to post comment:',
              res.status,
              await res.text()
            );
            return;
          }
          const comment = await res.json();
          this.task.comments = [
            ...(this.task.comments || []),
            comment
          ];
          this.newComment = '';
        } finally {
          this.posting = false;
        }
      },

      async addSubtask() {
        if (!this.newSubtaskName.trim() || this.addingSubtask) return;
        this.addingSubtask = true;
        try {
          const res = await fetch(`/tasks/${this.task.id}/subtasks`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            },
            body: JSON.stringify({ task_name: this.newSubtaskName })
          });
          if (res.ok) {
            const subtask = await res.json();
            this.task.subtasks = [...(this.task.subtasks || []), subtask];
            this.newSubtaskName = '';
            this.flash('Subtask added');
          }
        } catch(e) {
          console.error(e);
        } finally {
          this.addingSubtask = false;
        }
      },

      async deleteSubtask(subtaskId) {
        const confirmed = await confirmDialog({
          title: 'Delete this subtask?',
          text: 'This action is permanent and cannot be undone.',
          confirmText: 'Yes, delete it',
        });

        if (!confirmed) return;

        try {
          const res = await fetch(`/tasks/subtasks/${subtaskId}`, {
            method: 'DELETE',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            }
          });

          if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alertDialog({ text: err.message || 'Failed to delete subtask.' });
            return;
          }

          this.flash('Subtask deleted');
          this.dirty = true;
          await this.show(this.task.id);
        } catch (e) {
          console.error('Delete subtask error:', e);
        }
      },

      async toggleSubtask(subtask) {
        try {
          const res = await fetch(`/tasks/subtasks/${subtask.id}/toggle`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            }
          });
          if (res.ok) {
            const data = await res.json();
            subtask.status = data.status;
            subtask.is_completed = data.is_completed;
            this.flash('Subtask updated');
          }
        } catch(e) {
          console.error(e);
        }
      },

      async uploadFile(event) {
        const file = event.target.files[0];
        if (!file || this.uploadingFile) return;
        this.uploadingFile = true;
        const formData = new FormData();
        formData.append('file', file);

        try {
          const res = await fetch(`/tasks/${this.task.id}/attachments`, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            },
            body: formData
          });
          if (res.ok) {
            const data = await res.json();
            this.task.attachments = [...(this.task.attachments || []), data.attachment];
            this.flash('File uploaded successfully');
          } else {
            alertDialog({ text: 'Failed to upload file. Max size: 20MB.' });
          }
        } catch(e) {
          console.error(e);
          alertDialog({ text: 'Upload failed.' });
        } finally {
          this.uploadingFile = false;
          event.target.value = '';
        }
      },

      async deleteAttachment(attachmentId) {
        const confirmed = await confirmDialog({
          title: 'Remove this attachment?',
          text: 'The file will be permanently deleted.',
          confirmText: 'Yes, remove it',
        });

        if (!confirmed) return;
        try {
          const res = await fetch(`/tasks/${this.task.id}/attachments/${attachmentId}`, {
            method: 'DELETE',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            }
          });
          if (res.ok) {
            this.task.attachments = (this.task.attachments || []).filter(a => a.id !== attachmentId);
            this.flash('Attachment removed');
          }
        } catch(e) {
          console.error(e);
        }
      },

      async acceptTask() {
        try {
          const res = await fetch(`/tasks/${this.task.id}/accept`, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            }
          });

          if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alertDialog({ text: err.message || 'Failed to accept task.' });
            return;
          }

          const data = await res.json().catch(() => ({}));
          // Hide the banner and flip the badge immediately, then resync.
          this.applyAssignmentPayload(data);
          this.flash('Task accepted! Agreed terms are now locked.');
          this.dirty = true;
          await this.show(this.task.id);
        } catch (e) {
          console.error(e);
        }
      },

      async submitRejection() {
        if (!this.rejectionReason.trim()) return;
        try {
          const res = await fetch(`/tasks/${this.task.id}/reject`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            },
            body: JSON.stringify({ rejection_reason: this.rejectionReason.trim() })
          });
          if (res.ok) {
            const data = await res.json().catch(() => ({}));
            this.showRejectModal = false;
            this.rejectionReason = '';
            this.applyAssignmentPayload(data);
            this.flash('Assignment rejected.');
            this.dirty = true;
            await this.show(this.task.id);
          } else {
            const err = await res.json().catch(() => ({}));
            alertDialog({ text: err.message || 'Failed to reject task.' });
          }
        } catch (e) {
          console.error(e);
        }
      },

      async assignAdditionalUser() {
        if (!this.newAssigneeUserId) return;
        try {
          const res = await fetch(`/tasks/${this.task.id}/assign-users`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            },
            body: JSON.stringify({
              users: [{ user_id: this.newAssigneeUserId, role_label: this.newAssigneeRole || 'Contributor' }]
            })
          });
          if (res.ok) {
            this.newAssigneeUserId = '';
            this.newAssigneeRole = '';
            this.flash('Team member assigned.');
            this.dirty = true;
            await this.show(this.task.id);
          } else {
            const err = await res.json().catch(() => ({}));
            alertDialog({ text: err.message || 'Failed to assign team member.' });
          }
        } catch (e) {
          console.error(e);
        }
      },

      async removeAssignee(userId) {
        if (!confirm('Remove this assigned team member?')) return;
        try {
          const res = await fetch(`/tasks/${this.task.id}/assignees/${userId}`, {
            method: 'DELETE',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': this.csrf()
            }
          });
          if (res.ok) {
            this.flash('Assignee removed.');
            this.dirty = true;
            await this.show(this.task.id);
          } else {
            const err = await res.json().catch(() => ({}));
            alertDialog({ text: err.error || err.message || 'Failed to remove assignee.' });
          }
        } catch (e) {
          console.error(e);
        }
      }
    };
  }

  // Global helper so any onclick="openTask(id, editMode)" in the page
  // can reach the Alpine component using Alpine v3's public API.
  window.openTask = (id, editMode = false) => {
    const panel = document.querySelector('[x-data^="taskPanel"]');
    if (!panel) {
      console.error('Task panel element not found.');
      return;
    }
    Alpine.$data(panel).show(id, editMode);
  };

  window.toggleTaskSubtask = (id) => {
    const panel = document.querySelector('[x-data^="taskPanel"]');
    if (panel) Alpine.$data(panel).toggleSubtask({ id });
  };

  window.deleteTaskSubtask = (id) => {
    const panel = document.querySelector('[x-data^="taskPanel"]');
    if (panel) Alpine.$data(panel).deleteSubtask(id);
  };
</script>
