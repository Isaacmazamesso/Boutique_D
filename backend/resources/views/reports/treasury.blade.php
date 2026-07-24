<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 2px; }
  .sub { color: #555; margin-bottom: 16px; }
  .kpis { display: table; width: 100%; margin-bottom: 16px; }
  .kpi { display: table-cell; width: 25%; padding: 8px 10px; border: 1px solid #ddd; }
  .kpi .label { font-size: 9px; color: #777; text-transform: uppercase; }
  .kpi .value { font-size: 15px; font-weight: bold; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
  th { background: #f5f5f5; text-transform: uppercase; font-size: 8px; color: #555; }
  td.r, th.r { text-align: right; }
</style>
</head>
<body>
  <h1>Boutique D — Rapport de trésorerie</h1>
  <div class="sub">Période du {{ $periode['debut'] }} au {{ $periode['fin'] }}</div>

  <div class="kpis">
    <div class="kpi"><div class="label">Espèces</div><div class="value">{{ number_format($encaissements['especes'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Mobile Money</div><div class="value">{{ number_format($encaissements['mobile_money'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Remboursements</div><div class="value">{{ number_format($encaissements['remboursements'], 0, ',', ' ') }} F</div></div>
    <div class="kpi"><div class="label">Net</div><div class="value">{{ number_format($encaissements['net'], 0, ',', ' ') }} F</div></div>
  </div>

  <table>
    <thead>
      <tr><th>Caissier</th><th>Ouverture</th><th>Fermeture</th><th class="r">Fonds départ</th><th class="r">Théorique</th><th class="r">Saisi</th><th class="r">Écart</th><th>Statut</th></tr>
    </thead>
    <tbody>
      @foreach ($sessions['detail'] as $s)
      <tr>
        <td>{{ $s['cashier'] ?? '—' }}</td>
        <td>{{ $s['ouverture'] }}</td>
        <td>{{ $s['fermeture'] ?? '—' }}</td>
        <td class="r">{{ number_format($s['fonds_depart'], 0, ',', ' ') }} F</td>
        <td class="r">{{ $s['theorique'] !== null ? number_format($s['theorique'], 0, ',', ' ') . ' F' : '—' }}</td>
        <td class="r">{{ $s['montant_saisi'] !== null ? number_format($s['montant_saisi'], 0, ',', ' ') . ' F' : '—' }}</td>
        <td class="r">{{ $s['ecart'] !== null ? number_format($s['ecart'], 0, ',', ' ') . ' F' : '—' }}</td>
        <td>{{ ucfirst($s['statut']) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
