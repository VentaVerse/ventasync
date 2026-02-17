@extends('layouts.app')
@section('breadcrumb', 'Dashboard')

@section('content')
<div class="page-header">
    <h2>Dashboard</h2>
</div>

<div class="card mb-16">
    <h3 class="section-title mt-0">Welcome back, {{ auth()->user()->name ?? 'User' }}</h3>
    <p class="text-secondary">This is your inventory management system. Use the sidebar to navigate between sections.</p>

    <div class="detail-grid mt-16">
        <div class="detail-section">
            <h4>Catalog</h4>
            <div class="detail-body">
                Manage your products, categories, manufacturers, and options from the Catalog section.
            </div>
        </div>
        <div class="detail-section">
            <h4>Sales</h4>
            <div class="detail-body">
                View and manage orders and order statuses from the Sales section.
            </div>
        </div>
        <div class="detail-section">
            <h4>Marketplace API</h4>
            <div class="detail-body">
                Connect to Shopee and Lazada to sync products and orders.
            </div>
        </div>
        <div class="detail-section">
            <h4>Settings</h4>
            <div class="detail-body">
                Configure users, user groups, and general website settings.
            </div>
        </div>
    </div>
</div>
@endsection
