<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 2px; }
  h2 { font-size: 13px; margin: 16px 0 6px; }
  .sub { color: #555; margin-bottom: 16px; }
  .kpis { display: table; width: 100%; margin-bottom: 16px; }
  .kpi { display: table-cell; width: 33.33%; padding: 8px 10px; border: 1px solid #ddd; }
  .kpi .label { font-size: 9px; color: #777; text-transform: uppercase; }
  .kpi .value { font-size: 15px; font-weight: bold; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th, td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
  th { background: #f5f5f5; text-transform: uppercase; font-size: 8px; color: #555; }
  td.r, th.r { text-align: right; }
</style>
</head>
<body>
  <h1>Boutique D — Rapport de stock</h1>
  <div class="sub">Mouvements du {{ $mouvements['periode']['debut'] }} au {{ $mouvements['periode']['fin'] }}</div>

  <div class="kpis">
    <div class="kpi"><div class="label">Valeur stock (achat)</div><div class="value">{{ number_format($valeur_stock['achat'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Valeur stock (vente)</div><div class="value">{{ number_format($valeur_stock['vente'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Marge brute</div><div class="value">{{ number_format($valeur_stock['marge_brute'], 0, ',', ' ') }} F ({{ $valeur_stock['marge_pct'] }}%)</div></div>
  </div>

  <h2>Entrées de stock ({{ $mouvements['nb_entrees'] }})</h2>
  <table>
    <thead><tr><th>Date</th><th>Produit</th><th class="r">Quantité</th><th class="r">Prix achat</th><th>Fournisseur</th></tr></thead>
    <tbody>
      @foreach ($mouvements['entrees'] as $e)
      <tr><td>{{ $e['date'] }}</td><td>{{ $e['product'] }}</td><td class="r">{{ $e['quantity'] }}</td><td class="r">{{ number_format($e['prix'], 0, ',', ' ') }} F</td><td>{{ $e['fournisseur'] ?? '—' }}</td></tr>
      @endforeach
    </tbody>
  </table>

  <h2>Alertes ({{ count($alertes['rupture']) + count($alertes['stock_bas']) }})</h2>
  <table>
    <thead><tr><th>Produit</th><th>Catégorie</th><th>Statut</th><th class="r">Quantité</th></tr></thead>
    <tbody>
      @foreach ($alertes['rupture'] as $p)
      <tr><td>{{ $p['name'] }}</td><td>{{ $p['category'] ?? '—' }}</td><td>Rupture</td><td class="r">0</td></tr>
      @endforeach
      @foreach ($alertes['stock_bas'] as $p)
      <tr><td>{{ $p['name'] }}</td><td>{{ $p['category'] ?? '—' }}</td><td>Stock bas</td><td class="r">{{ $p['quantity'] }} / {{ $p['seuil'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
