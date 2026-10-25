<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
@page { margin: 30px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #202d36; }
h1 { font-size: 20px; margin-bottom: 5px; }
h2 { font-size: 14px; margin-top: 25px; }
small { color: #65737e; }
table { border-collapse: collapse; width: 100%; margin-top: 12px; }
th, td { padding: 8px; border-bottom: 1px solid #dfe5e8; text-align: left; }
th { background: #f3f6f7; }
</style>
</head>
<body>
<h1>Probashi RemitHisab</h1>
<p>{{ ucfirst($mode) }} summary</p>
<small>Period: {{ $period->fromDate ?? 'All time' }} to {{ $period->toDate ?? 'Present' }} | Timezone: {{ $summary['timezone'] }}</small>
@if ($mode === 'sender')
<h2>Summary</h2>
<table>
<tr><th>Transfers</th><td>{{ $summary['times_sent'] }}</td></tr>
<tr><th>Calculated BDT</th><td>{{ $summary['family_bdt'] }}</td></tr>
<tr><th>Confirmed BDT</th><td>{{ $summary['confirmed_bdt'] }}</td></tr>
</table>
<h2>By currency</h2>
<table><thead><tr><th>Currency</th><th>Transfers</th><th>Amount</th><th>Bank charges</th><th>Calculated BDT</th></tr></thead><tbody>
@foreach ($summary['by_currency'] as $row)
<tr><td>{{ $row['currency'] }}</td><td>{{ $row['times_sent'] }}</td><td>{{ $row['total_sent'] }}</td><td>{{ $row['bank_charges'] }}</td><td>{{ $row['family_bdt'] }}</td></tr>
@endforeach
</tbody></table>
@elseif ($mode === 'student')
<h2>Balances by currency</h2>
<table><thead><tr><th>Currency</th><th>Opening</th><th>Received</th><th>Spent</th><th>Balance at end</th></tr></thead><tbody>
@forelse ($summary['by_currency'] as $row)
<tr><td>{{ $row['currency'] }}</td><td>{{ $row['opening_balance'] }}</td><td>{{ $row['period_received'] }}</td><td>{{ $row['period_spent'] }}</td><td>{{ $row['remaining_as_of_to'] }}</td></tr>
@empty
<tr><td colspan="5">No recorded activity.</td></tr>
@endforelse
</tbody></table>
@foreach ($summary['by_currency'] as $row)
@if (!empty($row['expense_categories']))
<h2>Expenses by category ({{ $row['currency'] }})</h2>
<table><thead><tr><th>Category</th><th>Amount</th></tr></thead><tbody>
@foreach ($row['expense_categories'] as $category => $amount)
<tr><td>{{ $category }}</td><td>{{ $amount }}</td></tr>
@endforeach
</tbody></table>
@endif
@endforeach
@else
<h2>By currency</h2>
<table><thead><tr><th>Currency</th><th>Received</th><th>Pending</th><th>Commission earned</th><th>BDT owed</th></tr></thead><tbody>
@foreach ($summary['by_currency'] as $row)
<tr><td>{{ $row['currency'] }}</td><td>{{ $row['received'] }}</td><td>{{ $row['pending'] }}</td><td>{{ $row['commission_earned'] }}</td><td>{{ $row['bdt_owed'] }}</td></tr>
@endforeach
</tbody></table>
<h2>BDT totals</h2>
<table>
@foreach ($summary['all_currencies'] as $key => $value)
<tr><th>{{ ucwords(str_replace('_', ' ', $key)) }}</th><td>{{ $value }}</td></tr>
@endforeach
</table>
@endif
<p><small>Summary generated from recorded data. No funds are transferred by this application.</small></p>
</body></html>
