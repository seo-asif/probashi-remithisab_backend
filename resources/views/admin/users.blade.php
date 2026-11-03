@extends('admin.layouts.app')
@section('title', 'Users &middot; Probashi Admin')
@push('head')
<link rel="stylesheet" href="{{ asset('css/admin/pages/users.css') }}">
@endpush
@section('content')
<div id="users-page">

  <div class="eyebrow">Account directory</div>
  <h1>Users</h1><p class="sub">Find accounts and review registration status. Changes to user accounts are not available in this phase.</p>
  <section class="panel" aria-label="User directory">
    <div class="panel-head"><div><h2>All accounts</h2><div class="muted">Search by name or email and filter by status.</div></div><span class="muted">{{ number_format($users->total()) }} matches</span></div>
    <form class="filters" method="GET" action="{{ route('admin.users') }}">
      <input aria-label="Search users" type="search" name="q" maxlength="100" placeholder="Search name or email" value="{{ $search }}">
      <select name="status" aria-label="Filter account status">
        <option value="all" @selected($status === 'all')>All statuses</option>
        <option value="active" @selected($status === 'active')>Active</option>
        <option value="suspended" @selected($status === 'suspended')>Suspended</option>
      </select>
      <button type="submit">Apply filters</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>User</th><th>Account ID</th><th>Status</th><th>Email verified</th><th>Joined</th></tr></thead><tbody>
    @forelse($users as $user)
      <tr><td><a class="name profile-link" href="{{ route('admin.users.show', $user) }}">{{ $user->name }}</a><div class="email">{{ $user->email }}</div></td><td>#{{ $user->id }}</td><td><span class="pill {{ $user->status->value === 'active' ? '' : 'off' }}">{{ ucfirst($user->status->value) }}</span></td><td>{{ $user->email_verified_at ? 'Verified' : 'Unverified' }}</td><td>{{ $user->created_at?->format('d M Y') ?? 'Unknown' }}</td></tr>
    @empty
      <tr><td colspan="5" class="empty">No accounts match your filters.</td></tr>
    @endforelse
    </tbody></table></div>
    <div class="foot"><span>{{ $users->total() ? $users->firstItem().'-'.$users->lastItem().' of '.$users->total() : '0 results' }}</span><div class="pagination">
    @if($users->onFirstPage())<span class="page disabled">Previous</span>@else<a class="page" href="{{ $users->previousPageUrl() }}" rel="prev">Previous</a>@endif
    <span>Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
    @if($users->hasMorePages())<a class="page" href="{{ $users->nextPageUrl() }}" rel="next">Next</a>@else<span class="page disabled">Next</span>@endif
    </div></div>
  </section>
 </div>
</main>
</div>
</body>
</html>

</div>
@endsection
