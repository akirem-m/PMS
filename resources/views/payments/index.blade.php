@extends('layouts.app')
@section('title', 'Payments & Costs')
@section('crumb', 'Payments')

@section('content')
@php
    /*
     * Per-row workflow permissions, computed once so the table header and the
     * action cells agree. Approvers are Team Leads / Project Managers;
     * disbursers are Heads of Office / Finance (PaymentPolicy).
     */
    $rowActions = $payments->getCollection()->mapWithKeys(fn ($payment) => [
        $payment->payment_id => [
            'approve' => auth()->user()->can('approve', $payment),
            'disburse' => auth()->user()->can('disburse', $payment),
            'update' => auth()->user()->can('update', $payment),
            'delete' => auth()->user()->can('delete', $payment),
        ],
    ]);
@endphp
<div x-data="{ recordModal: false, editModal: false, expenseModal: false, rejectModal: false, rejectPaymentId: null, currentPayment: {} }">
    <div class="page-head">
        <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <a href="{{ route('budgets.index') }}" class="btn btn-ghost" style="padding:2px 8px; font-size:12px;">←
                    Budgets</a>
                <h1 style="margin:0;">Payments &amp; Costs</h1>
            </div>
            <div class="page-sub">Comprehensive transaction records across projects, phases, and tasks</div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            @if ($assignableTasks->isNotEmpty())
                <button type="button" class="btn btn-primary" @click="expenseModal = true">+ Log Expenditure</button>
            @endif
            @if (auth()->user()->can('manage_budgets') || auth()->user()->isDirectorOrAdmin())
                <button type="button" class="btn btn-accent" @click="recordModal = true">+ Record Payment</button>
            @endif
        </div>
    </div>

    <!-- Metric Overview Cards -->
    <div class="grid grid-4" style="margin-bottom:20px;">
        <div class="card stat-card">
            <div class="stat-label">Total Expenditure</div>
            <div class="stat-value" style="font-size:20px;">ETB {{ number_format($totalPayments) }}</div>
            <div class="stat-delta">{{ $completedCount }} completed payment(s)</div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Awaiting Approval</div>
            <div class="stat-value" style="font-size:20px; color:var(--ink-soft);">ETB
                {{ number_format($pendingPayments) }}</div>
            <div class="stat-delta">{{ $pendingCount }} submitted expense(s)</div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Awaiting Disbursement</div>
            <div class="stat-value" style="font-size:20px; color:var(--accent);">ETB
                {{ number_format($approvedPayments) }}</div>
            <div class="stat-delta">{{ $approvedCount }} approved by Team Lead / PM</div>
        </div>
        <div class="card stat-card">
            <div class="stat-label">Total Transactions</div>
            <div class="stat-value" style="font-size:20px; color:var(--primary);">{{ $payments->total() }}</div>
            <div class="stat-delta">Logged in audit trail</div>
        </div>
    </div>

    <!-- Filter Controls -->
    <div class="card card-pad" style="margin-bottom:20px; padding:12px 16px;">
        <form method="GET" action="{{ route('payments.index') }}"
            style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <div style="flex:1; min-width:200px;">
                <select name="project_id"
                    style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;"
                    onchange="this.form.submit()">
                    <option value="">— All Projects —</option>
                    @foreach ($projects as $pr)
                        <option value="{{ $pr->project_id }}" {{ request('project_id') == $pr->project_id ? 'selected' : '' }}>
                            {{ $pr->project_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <select name="status"
                    style="border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;"
                    onchange="this.form.submit()">
                    <option value="">— All Statuses —</option>
                    <option value="Completed" {{ request('status') === 'Completed' ? 'selected' : '' }}>Completed</option>
                    <option value="Pending" {{ request('status') === 'Pending' ? 'selected' : '' }}>Pending</option>
                    <option value="Approved" {{ request('status') === 'Approved' ? 'selected' : '' }}>Approved</option>
                    <option value="Cancelled" {{ request('status') === 'Cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>

            @if (request()->hasAny(['project_id', 'status', 'task_id']))
                <a href="{{ route('payments.index') }}" class="btn btn-ghost"
                    style="padding:6px 12px; font-size:12px;">Clear Filters</a>
            @endif
        </form>
    </div>
  <!-- Payments Table Card -->
  <div class="card" style="overflow-x:auto;">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Project / Task</th>
          <th>Recipient / Payee</th>
          <th>Amount (ETB)</th>
          <th>Reference #</th>
          <th>Status</th>
          <th>Recorded By</th>
          @if ($rowActions->contains(fn ($a) => $a['approve'] || $a['disburse'] || $a['update'] || $a['delete']))
            <th style="text-align:right;">Action</th>
          @endif
        </tr>
      </thead>
      <tbody>
        @forelse ($payments as $payment)
          <tr>
            <td style="white-space:nowrap; font-size:12.5px;">
              {{ optional($payment->payment_date)->format('d M Y') }}
            </td>
            <td>
              <div style="font-weight:700; color:var(--ink);">
                <a href="{{ route('projects.show', $payment->project) }}" class="link-small">
                  {{ $payment->project->project_name }}
                </a>
              </div>
              @if ($payment->task)
                <div style="font-size:11.5px; color:var(--ink-muted);">
                  Task: {{ $payment->task->task_name }}
                </div>
              @elseif ($payment->phase)
                <div style="font-size:11.5px; color:var(--ink-muted);">
                  Phase: {{ $payment->phase->phase_name }}
                </div>
              @endif
              @if ($payment->sop_process)
                <div style="font-size:11px; color:var(--ink-faint); margin-top:2px;">
                  SOP: {{ $payment->sop_process }}
                </div>
              @endif
            </td>
            <td>
              <div style="font-weight:600;">{{ $payment->recipient }}</div>
              @if ($payment->description)
                <div style="font-size:11px; color:var(--ink-muted);">{{ Str::limit($payment->description, 50) }}</div>
              @endif
            </td>
            <td style="font-weight:700; color:var(--ink); white-space:nowrap;">
              ETB {{ number_format($payment->amount, 2) }}
            </td>
            <td class="mono" style="font-size:12px; color:var(--ink-soft);">
              {{ $payment->reference_number ?: '—' }}
            </td>
            <td>
              <span class="badge {{ match ($payment->payment_status) {
                  'Completed' => 'b-active',
                  'Approved' => 'b-review',
                  'Pending' => 'b-planning',
                  'Cancelled' => 'b-blocked',
                  default => 'b-risk',
              } }}">
                {{ $payment->payment_status }}
              </span>
              @if ($payment->approved_at)
                <div style="font-size:10.5px; color:var(--ink-muted); margin-top:3px;">
                  Approved {{ $payment->approved_at->diffForHumans() }}@if ($payment->approver) by {{ $payment->approver->full_name }}@endif
                </div>
              @endif
              @if ($payment->disbursed_at)
                <div style="font-size:10.5px; color:var(--success); margin-top:2px;">
                  Disbursed {{ $payment->disbursed_at->diffForHumans() }}@if ($payment->disburser) by {{ $payment->disburser->full_name }}@endif
                </div>
              @endif
              @if ($payment->rejection_reason)
                <div style="font-size:10.5px; color:var(--danger); margin-top:2px;">{{ $payment->rejection_reason }}</div>
              @endif
            </td>
            <td style="font-size:12px; color:var(--ink-soft);">
              {{ optional($payment->creator)->full_name ?? 'System' }}
            </td>
            @php $actions = $rowActions[$payment->payment_id] ?? []; @endphp
            @if ($rowActions->contains(fn ($a) => $a['approve'] || $a['disburse'] || $a['update'] || $a['delete']))
              <td style="text-align:right; white-space:nowrap;">
                @if ($payment->payment_status === 'Pending' && ($actions['approve'] ?? false))
                  <form method="POST" action="{{ route('payments.approve', $payment) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-ghost" style="padding:3px 8px; font-size:11px; color:var(--success);" title="Approve Expenditure">✓ Approve</button>
                  </form>
                  <button type="button" class="btn btn-ghost" style="padding:3px 8px; font-size:11px; color:var(--danger);" @click="rejectPaymentId = {{ $payment->payment_id }}; rejectModal = true;" title="Decline Expenditure">✕ Decline</button>
                @endif
                @if ($payment->payment_status === 'Approved' && ($actions['disburse'] ?? false))
                  <form method="POST" action="{{ route('payments.disburse', $payment) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-accent" style="padding:3px 8px; font-size:11px;" title="Confirm Disbursement">💸 Confirm Disbursement</button>
                  </form>
                @endif
                @if ($actions['delete'] ?? false)
                  <form method="POST" action="{{ route('payments.destroy', $payment) }}" style="display:inline;" onsubmit="return confirm('Delete this payment record of ETB {{ number_format($payment->amount) }}?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-ghost" style="padding:3px 8px; font-size:11px; color:var(--danger);" title="Delete Record">✕</button>
                  </form>
                @endif
              </td>
            @endif
          </tr>
        @empty
          <tr>
            <td colspan="8" style="text-align:center; padding:30px; color:var(--ink-muted);">
              No payment records found matching the criteria.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div style="margin-top:16px;">
    {{ $payments->links() }}
  </div>

  <!-- Record Payment Modal -->
  <div x-show="recordModal" x-cloak style="position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; padding:16px;">
    <div class="card card-pad" @click.away="recordModal = false" style="max-width:540px; width:100%; max-height:90vh; overflow-y:auto; background:var(--surface);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3 style="margin:0; font-size:16px;">Record Payment / Cost</h3>
        <button type="button" class="btn btn-ghost" @click="recordModal = false" style="padding:4px 8px;">✕</button>
      </div>

      <form method="POST" action="{{ route('payments.store') }}">
        @csrf
        <div class="form-field">
          <label>Project <span style="color:var(--danger);">*</span></label>
          <select name="project_id" required style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
            <option value="">— Select Project —</option>
            @foreach ($projects as $pr)
              <option value="{{ $pr->project_id }}">{{ $pr->project_name }}</option>
            @endforeach
          </select>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-field">
            <label>Amount (ETB) <span style="color:var(--danger);">*</span></label>
            <input type="number" step="0.01" min="0.01" name="amount" required placeholder="e.g. 25000" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
          </div>
          <div class="form-field">
            <label>Payment Date <span style="color:var(--danger);">*</span></label>
            <input type="date" name="payment_date" required value="{{ date('Y-m-d') }}" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
          </div>
        </div>

        <div class="form-field">
          <label>Recipient / Payee <span style="color:var(--danger);">*</span></label>
          <input type="text" name="recipient" required placeholder="e.g. Vendor name, consultant, or staff member" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div class="form-field">
            <label>Payment Status</label>
            <select name="payment_status" required style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
              <option value="Completed" selected>Completed</option>
              <option value="Pending">Pending</option>
              <option value="Approved">Approved</option>
              <option value="Cancelled">Cancelled</option>
            </select>
          </div>
          <div class="form-field">
            <label>Reference # / Invoice #</label>
            <input type="text" name="reference_number" placeholder="e.g. INV-2026-001" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
          </div>
        </div>

        <div class="form-field">
          <label>SOP / Process Reference</label>
          <input type="text" name="sop_process" placeholder="e.g. Procurement SOP-04, Direct Payment" style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
        </div>

        <div class="form-field">
          <label>Description / Remarks</label>
          <textarea name="description" rows="2" placeholder="Purpose of this disbursement..." style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;"></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
          <button type="button" class="btn btn-ghost" @click="recordModal = false">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Payment</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Log Expenditure Modal: contributors submit against their own task's
       phase funds; the request enters the approval queue as Pending. -->
  <div x-show="expenseModal" x-cloak style="position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; padding:16px;">
    <div class="card card-pad" @click.away="expenseModal = false" style="max-width:540px; width:100%; max-height:90vh; overflow-y:auto; background:var(--surface);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:16px;">Log Expenditure</h3>
        <button type="button" class="btn btn-ghost" @click="expenseModal = false" style="padding:4px 8px;">✕</button>
      </div>
      <p style="font-size:12.5px; color:var(--ink-soft); margin-bottom:12px;">
        Your expense goes to the Team Lead / Project Manager for approval, then to the Head of Office / Finance to confirm disbursement. It is charged against the task's phase funds.
      </p>

      <form method="POST" action="{{ route('payments.store') }}">
        @csrf
        <div class="form-field">
          <label>Task <span style="color:var(--danger);">*</span></label>
          <select name="task_id" required style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;">
            <option value="">— Select the task this expense belongs to —</option>
            @foreach ($assignableTasks as $task)
              <option value="{{ $task->task_id }}">
                {{ $task->project?->project_name ?? 'Project' }} — {{ $task->task_name }}
              </option>
            @endforeach
          </select>
        </div>

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
          <textarea name="description" rows="2" placeholder="What this expense covers..." style="width:100%; border:1px solid var(--line); border-radius:6px; padding:6px 10px; font-size:13px; font-family:inherit;"></textarea>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px;">
          <button type="button" class="btn btn-ghost" @click="expenseModal = false">Cancel</button>
          <button type="submit" class="btn btn-primary">Submit for Approval</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Decline Expenditure Modal -->
  <div x-show="rejectModal" x-cloak style="position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; padding:16px;">
    <div class="card card-pad" @click.away="rejectModal = false" style="max-width:460px; width:100%; background:var(--surface);">
      <h3 style="margin-top:0; color:var(--danger); font-size:15px;">Decline Expenditure</h3>
      <p style="font-size:12.5px; color:var(--ink-soft); margin-bottom:12px;">Tell the submitter why this expense is being declined.</p>
      <form method="POST" :action="'/payments/' + rejectPaymentId + '/reject'">
        @csrf
        <textarea name="rejection_reason" rows="3" required placeholder="Reason for declining..." style="width:100%; border:1px solid var(--line); border-radius:6px; padding:8px 10px; font-size:13px; font-family:inherit;"></textarea>
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
          <button type="button" class="btn btn-ghost" @click="rejectModal = false">Cancel</button>
          <button type="submit" class="btn btn-accent" style="background:var(--danger); border-color:var(--danger);">Confirm Decline</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
