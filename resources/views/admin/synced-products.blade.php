@extends('layouts.app')
@section('content')
<div class="container">
  <h2>Synced Products (from Main Panel)</h2>
  <table class="table">
    <thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Active</th><th>Packages</th></tr></thead>
    <tbody>
    @foreach($products as $p)
      <tr>
        <td>{{ $p->id }}</td>
        <td>{{ $p->name }}</td>
        <td>{{ $p->category }}</td>
        <td>{{ $p->is_active ? 'Yes' : 'No' }}</td>
        <td>{{ $p->packages->count() }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
</div>
@endsection
