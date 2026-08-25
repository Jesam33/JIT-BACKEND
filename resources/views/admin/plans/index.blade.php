@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Plans</h1>
    <form method="post" action="{{ route('admin.plans.store') }}">
        @csrf
        <div>
            <label>Name</label>
            <input name="name" required />
        </div>
        <div>
            <label>Slug</label>
            <input name="slug" required />
        </div>
        <div>
            <label>Price</label>
            <input name="price" type="number" step="0.01" required />
        </div>
        <div>
            <label>Description</label>
            <textarea name="description"></textarea>
        </div>
        <button type="submit">Create</button>
    </form>

    <h2>Existing Plans</h2>
    <ul>
        @foreach($plans as $plan)
            <li>{{ $plan->name }} - {{ $plan->price }}
                <form method="post" action="{{ route('admin.plans.delete', $plan->id) }}" style="display:inline">
                    @csrf
                    <button type="submit">Delete</button>
                </form>
            </li>
        @endforeach
    </ul>
</div>
@endsection
