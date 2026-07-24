<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; padding: 24px; }
  h1 { font-size: 18px; margin-bottom: 2px; }
  .sub { color: #555; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { padding: 5px 6px; border-bottom: 1px solid #eee; text-align: left; font-size: 10px; }
  th { background: #f5f5f5; text-transform: uppercase; font-size: 8px; color: #555; }
  td.r, th.r { text-align: right; }
</style>
</head>
<body>
  <h1>Boutique D — Rapport performance employés</h1>
  <div class="sub">Période du {{ $periode['debut'] }} au {{ $periode['fin'] }}</div>

  <table>
    <thead>
      <tr><th>Employé</th><th>Rôle</th><th class="r">Ventes</th><th class="r">CA</th><th class="r">Panier moy.</th><th class="r">Remboursements</th><th class="r">Sessions</th><th class="r">Heures</th></tr>
    </thead>
    <tbody>
      @foreach ($employes as $e)
      <tr>
        <td>{{ $e['name'] }}</td>
        <td>{{ $e['role'] }}</td>
        <td class="r">{{ $e['nb_ventes'] }}</td>
        <td class="r">{{ number_format($e['montant_vendu'], 0, ',', ' ') }} F</td>
        <td class="r">{{ number_format($e['panier_moyen'], 0, ',', ' ') }} F</td>
        <td class="r">{{ $e['nb_remboursements'] }}</td>
        <td class="r">{{ $e['nb_sessions'] }}</td>
        <td class="r">{{ $e['heures_connexion'] }}h</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
