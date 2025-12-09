<?php
/*******************************************************
 * Sports Hub - Solución final "siempre HOY" (GMT-5)
 *
 * 1) Intenta usar API-FOOTBALL (gratis con API key) para obtener
 *    los eventos de HOY por zona horaria.
 *    - Ventaja: control total de fecha/horario.
 *    - Poner tu API key en API_FOOTBALL_KEY.
 * 2) Si no hay API key o falla la llamada, hace fallback al iframe
 *    de sport-tv-guide.live (que puede mostrar la fecha del sitio).
 *******************************************************/

/** ===== CONFIG ===== */
const TZ_REGION      = 'America/Lima'; // GMT-5
const TZ_OFFSET      = '-5';
const API_FOOTBALL_KEY = ''; // <-- Pega aquí tu clave de https://dashboard.api-football.com/
const API_FOOTBALL_URL = 'https://v3.football.api-sports.io/fixtures';

/** Proveedor iframe fallback */
const IFRAME_BASE = 'https://sport-tv-guide.live/';

/** ===== Helpers de fecha ===== */
function todayLocalYmd(string $tz = TZ_REGION): string {
  try {
    $dt = new DateTime('now', new DateTimeZone($tz));
    return $dt->format('Y-m-d');
  } catch (Throwable $e) {
    return date('Y-m-d');
  }
}

/** ===== Fuente 1: API-FOOTBALL (Soccer) ===== */
function getSoccerEventsToday(string $leagueFilter = null): array {
  if (empty(API_FOOTBALL_KEY)) return []; // sin clave -> no usamos API
  $date = todayLocalYmd();
  $params = http_build_query([
    'date'     => $date,
    'timezone' => TZ_REGION,
  ]);
  $url = API_FOOTBALL_URL . '?' . $params;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
      'x-rapidapi-key: ' . API_FOOTBALL_KEY,
      'x-rapidapi-host: v3.football.api-sports.io',
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'SportsHub/1.0 (+https://tu-dominio)'
  ]);
  $res = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($res === false || $status >= 400) return [];

  $json = json_decode($res, true);
  if (!isset($json['response']) || !is_array($json['response'])) return [];

  $items = [];
  foreach ($json['response'] as $fx) {
    $fixture = $fx['fixture'] ?? [];
    $league  = $fx['league']  ?? [];
    $teams   = $fx['teams']   ?? [];
    $go      = [
      'title'  => ($teams['home']['name'] ?? 'Local') . ' vs ' . ($teams['away']['name'] ?? 'Visitante'),
      'date'   => $fixture['date'] ?? null,
      'league' => $league['name'] ?? null,
      'country'=> $league['country'] ?? null,
      'status' => $fixture['status']['short'] ?? null,
      'venue'  => ($fixture['venue']['name'] ?? null),
      'city'   => ($fixture['venue']['city'] ?? null),
      'image'  => $league['logo'] ?? null,
      'url'    => '#',
    ];
    if ($leagueFilter && strcasecmp($leagueFilter, 'Todas las Ligas') !== 0) {
      if (strcasecmp($go['league'] ?? '', $leagueFilter) !== 0) continue;
    }
    $items[] = $go;
  }
  return $items;
}

/** ===== UI: filtros ===== */
$selectedSport  = $_GET['sport']  ?? 'Soccer';
$selectedLeague = $_GET['league'] ?? 'Todas las Ligas';
$todayYmd = todayLocalYmd();

/** ===== Lógica ===== */
$events = [];
if (strcasecmp($selectedSport, 'Soccer') === 0) {
  $events = getSoccerEventsToday($selectedLeague);
}

/** ===== Iframe de fallback ===== */
$iframeUrl = IFRAME_BASE . '?date=' . urlencode($todayYmd) . '&tz=' . urlencode(TZ_OFFSET);

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sports Hub</title>
<style>
  :root { --bg:#0b0f14; --fg:#e7f0f8; --muted:#a9b3bd; --card:#121821; --border:#1e2732; --accent:#10b3ff; }
  html, body { background: var(--bg); color: var(--fg); margin:0; font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; }
  .container { max-width: 1100px; margin: 0 auto; padding: 24px; }
  .logo { font-size: 28px; font-weight: 800; color: var(--accent); }
  .subtitle { color: var(--muted); margin-top: 6px; }

  .filters { margin-top: 18px; display:flex; gap:12px; flex-wrap: wrap; align-items:center; }
  label { display:flex; align-items:center; gap:8px; }
  select, .btn { background: var(--card); color: var(--fg); border:1px solid var(--border); border-radius: 12px; padding:10px 12px; }
  .btn { cursor:pointer; }

  .section-title { font-weight: 800; font-size: 18px; margin: 18px 0 10px; }
  .empty-state { margin: 24px 0; text-align:center; color: var(--muted); }

  .events-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; margin-top: 14px; }
  .event-card { background: var(--card); border:1px solid var(--border); border-radius:16px; overflow: hidden; display:flex; flex-direction:column; }
  .event-body { padding: 12px; display:flex; flex-direction:column; gap:8px; }
  .event-title { font-size: 16px; font-weight: 800; }
  .event-meta { font-size: 13px; color: var(--muted); }
  .event-venue { font-size: 13px; color: var(--muted); }
  .event-img { width: 100%; height: 140px; object-fit: contain; background:#0f141b; }
  .badge { font-size: 11px; padding: 2px 6px; border:1px solid var(--border); border-radius: 10px; }

  .ads-embed-wrap { margin-top: 16px; border:1px solid var(--border); border-radius: 16px; overflow:hidden; background: var(--card); }
  .ads-embed { width: 100%; height: 1500px; border:0; display:block; }
</style>
</head>
<body>
  <div class="container">
    <div class="logo">⚡ SPORTS HUB</div>
    <div class="subtitle">Tu Guía Definitiva de Eventos Deportivos</div>

    <form class="filters" method="get">
      <label>🏆 DEPORTE
        <select name="sport">
          <?php
            $sports = ['Soccer','Basketball','Baseball','Tennis','Hockey','MMA','Boxing','Football'];
            foreach ($sports as $s) {
              $sel = (strcasecmp($selectedSport,$s)===0) ? 'selected' : '';
              echo '<option '.$sel.' value="'.htmlspecialchars($s, ENT_QUOTES, 'UTF-8').'">'.$s.'</option>';
            }
          ?>
        </select>
      </label>
      <label>🥇 LIGA
        <select name="league">
          <?php
            $leagues = ['Todas las Ligas','Premier League','La Liga','Serie A','Bundesliga','Ligue 1','MLS'];
            foreach ($leagues as $l) {
              $sel = (strcasecmp($selectedLeague,$l)===0) ? 'selected' : '';
              echo '<option '.$sel.' value="'.htmlspecialchars($l, ENT_QUOTES, 'UTF-8').'">'.$l.'</option>';
            }
          ?>
        </select>
      </label>
      <button class="btn" type="submit">Aplicar</button>
    </form>

    <?php if (!empty($events)): ?>
      <h3 class="section-title">📅 Eventos de hoy (<?= htmlspecialchars($todayYmd, ENT_QUOTES, 'UTF-8'); ?>)</h3>
      <div class="events-grid">
        <?php foreach ($events as $ev): ?>
          <article class="event-card">
            <?php if (!empty($ev['image'])): ?>
              <img class="event-img" src="<?= htmlspecialchars($ev['image'], ENT_QUOTES, 'UTF-8'); ?>" alt="logo">
            <?php endif; ?>
            <div class="event-body">
              <div class="event-title"><?= htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="event-meta">
                <?= htmlspecialchars($ev['league'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                <?php if(!empty($ev['country'])): ?> • <?= htmlspecialchars($ev['country'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
              </div>
              <?php if(!empty($ev['date'])): ?>
                <span class="badge"><?= htmlspecialchars((new DateTime($ev['date']))->setTimezone(new DateTimeZone(TZ_REGION))->format('H:i'), ENT_QUOTES, 'UTF-8'); ?> <?= htmlspecialchars(TZ_OFFSET, ENT_QUOTES, 'UTF-8'); ?></span>
              <?php endif; ?>
              <?php if(!empty($ev['venue']) || !empty($ev['city'])): ?>
                <div class="event-venue"><?= htmlspecialchars($ev['venue'] ?? '', ENT_QUOTES, 'UTF-8'); ?> <?= !empty($ev['city']) ? ' - '.htmlspecialchars($ev['city'], ENT_QUOTES, 'UTF-8') : '' ?></div>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <!-- Fallback al sitio externo -->
      <h3 class="section-title">📣 Anuncios de eventos (embebido)</h3>
      <div class="ads-embed-wrap">
        <iframe class="ads-embed" src="<?= htmlspecialchars($iframeUrl, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" referrerpolicy="no-referrer"></iframe>
      </div>
      <div class="empty-state">
        <small>Consejo: agrega tu API key de API-FOOTBALL para mostrar SIEMPRE los eventos de hoy en tu zona.</small>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>