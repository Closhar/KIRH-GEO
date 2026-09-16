<style>
.kg-form{display:grid;gap:1rem;max-width:65rem}.kg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}.kg-field{display:grid;gap:.35rem}.kg-input{width:100%;padding:.65rem;border:1px solid #9ca3af;border-radius:.5rem;background:transparent;color:inherit}.kg-input option{color:#111;background:#fff}.kg-section{padding:1.2rem;border:1px solid #9ca3af;border-radius:.75rem;margin-top:1rem}.kg-section h2{font-size:1.1rem;font-weight:600;margin-bottom:.75rem}.kg-table{width:100%;text-align:left;border-collapse:collapse}.kg-table th,.kg-table td{padding:.65rem;border-bottom:1px solid #9ca3af}.kg-table-wrap{overflow:auto}.kg-errors{color:#dc2626;padding:1rem;border:1px solid currentColor;border-radius:.5rem}.kg-hint{font-size:.875rem;opacity:.8}.kg-actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1rem}
</style>
@if($errors->any())
<div class="kg-errors" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
@endif
