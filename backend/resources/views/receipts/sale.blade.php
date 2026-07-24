<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111; padding: 8px 10px; }
  h1 { font-size: 13px; text-align: center; }
  .sub { text-align: center; color: #444; margin-top: 2px; }
  hr { border: none; border-top: 1px dashed #999; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 2px 0; vertical-align: top; }
  td.r { text-align: right; white-space: nowrap; }
  .total td { font-weight: bold; font-size: 11px; padding-top: 4px; }
  .footer { text-align: center; margin-top: 10px; color: #555; }
</style>
</head>
<body>
  <h1>Boutique D</h1>
  <div class="sub">{{ $sale['receipt_number'] }}</div>
  <div class="sub">{{ $sale['date'] }}</div>
  <div class="sub">Caissier : {{ $sale['cashier'] ?? '—' }}</div>
  <hr>
  <table>
    @foreach ($sale['items'] as $item)
    <tr>
      <td>{{ $item['product'] }} × {{ $item['quantity'] }}</td>
      <td class="r">{{ number_format($item['total'], 0, ',', ' ') }} F</td>
    </tr>
    @endforeach
  </table>
  <hr>
  <table>
    <tr><td>Sous-total</td><td class="r">{{ number_format($sale['subtotal'], 0, ',', ' ') }} F</td></tr>
    @if (($sale['discount_value'] ?? 0) > 0)
    <tr><td>Remise</td><td class="r">- {{ number_format($sale['discount_value'], 0, ',', ' ') }}{{ $sale['discount_type'] === 'percent' ? ' %' : ' F' }}</td></tr>
    @endif
    <tr class="total"><td>TOTAL</td><td class="r">{{ number_format($sale['total'], 0, ',', ' ') }} FCFA</td></tr>
    <tr><td>Paiement</td><td class="r">{{ $sale['payment_method'] === 'especes' ? 'Espèces' : 'Mobile Money' }}</td></tr>
    @if (($sale['amount_paid'] ?? 0) > 0)
    <tr><td>Montant reçu</td><td class="r">{{ number_format($sale['amount_paid'], 0, ',', ' ') }} F</td></tr>
    @endif
    @if (($sale['change_given'] ?? 0) > 0)
    <tr><td>Monnaie rendue</td><td class="r">{{ number_format($sale['change_given'], 0, ',', ' ') }} F</td></tr>
    @endif
  </table>
  <hr>
  <div class="footer">Merci de votre visite !</div>
</body>
</html>
