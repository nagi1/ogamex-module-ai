<?php
// Pure-calculation economy benchmark (see plan/rl/rust-performance-research.md).
// N planets, greedy policy over 6 buildings, discrete-event jumps for DAYS. Formulas follow OGameX BuildingObjects.
$N = (int)($argv[1] ?? 10000); $DAYS = (int)($argv[2] ?? 30);
const BASE = [[60,15,1.5],[48,24,1.6],[225,75,1.5],[75,30,1.5],[1000,0,2.0],[1000,500,2.0]]; // mm, cm, dsynth, solar, mstore, cstore
function cap(int $lvl): float { return 5000*floor(2.5*exp(20*$lvl/33)); }
$seed = 12345;
function rnd(int &$s): float { $s = ($s * 1103515245 + 12345) & 0x7fffffff; return $s / 0x7fffffff; }
$P = [];
for ($i=0;$i<$N;$i++) $P[] = ['L'=>[0,0,0,0,0,0], 'r'=>[500.0,500.0,0.0], 'T'=>-40+rnd($seed)*120, 'busy'=>-1, 'end'=>0.0];
$horizon = $DAYS*86400.0; $decisions = 0; $events = 0;
$t0 = hrtime(true);
foreach ($P as &$p) {
  $now = 0.0;
  while ($now < $horizon) {
    $L = $p['L'];
    $use = 10*$L[0]*1.1**$L[0] + 10*$L[1]*1.1**$L[1] + 20*$L[2]*1.1**$L[2];
    $e = 20*$L[3]*1.1**$L[3];
    $f = $use <= 0 ? 1.0 : min(1.0, $e/$use);
    $rate = [30 + 30*$L[0]*1.1**$L[0]*$f, 15 + 20*$L[1]*1.1**$L[1]*$f, 10*$L[2]*1.1**$L[2]*(1.44-0.004*$p['T'])*$f];
    $caps = [cap($L[4]), cap($L[5]), cap(0)];
    if ($p['busy'] < 0) {
      $decisions++;
      $best = -1; $bestEta = INF;
      for ($k=0;$k<6;$k++) {
        if ($f < 0.999 && $k != 3) continue;
        $fac = BASE[$k][2]**$L[$k]; $cm = BASE[$k][0]*$fac; $cc = BASE[$k][1]*$fac;
        if ($cm > $caps[0] || $cc > $caps[1]) continue;
        $eta = max(0.0, ($cm-$p['r'][0])/$rate[0], ($cc-$p['r'][1])/$rate[1])*3600;
        if ($eta < $bestEta) { $bestEta = $eta; $best = $k; }
      }
      if ($best < 0) { // nothing fits storage: upgrade the binding store
        $best = BASE[0][0]*BASE[0][2]**$L[0] > $caps[0] ? 4 : 5;
        $fac = BASE[$best][2]**$L[$best]; $bestEta = max(0.0, (BASE[$best][0]*$fac-$p['r'][0])/$rate[0], (BASE[$best][1]*$fac-$p['r'][1])/$rate[1])*3600;
      }
      if ($bestEta <= 0) {
        $fac = BASE[$best][2]**$L[$best]; $cm=BASE[$best][0]*$fac; $cc=BASE[$best][1]*$fac;
        $p['r'][0]-=$cm; $p['r'][1]-=$cc; $p['busy']=$best; $p['end']=$now + max(1.0, ($cm+$cc)/2500*3600); $next = $p['end'];
      } else { $next = $now + max(1.0, $bestEta); }
    } else { $next = $p['end']; }
    if ($next > $horizon) $next = $horizon;
    $h = ($next-$now)/3600;
    for ($k=0;$k<3;$k++) { if ($p['r'][$k] < $caps[$k]) $p['r'][$k] = min($caps[$k], $p['r'][$k] + $rate[$k]*$h); }
    $now = $next; $events++;
    if ($p['busy'] >= 0 && $now >= $p['end']) { $p['L'][$p['busy']]++; $p['busy'] = -1; }
  }
}
unset($p);
$ms = (hrtime(true)-$t0)/1e6;
$sum = 0; foreach ($P as $p) $sum += array_sum($p['L']) * 1000 + (int)$p['r'][0];
printf("php N=%d days=%d decisions=%d events=%d time=%.1fms checksum=%d ns/event=%.0f planet-months/s=%.0f\n", $N,$DAYS,$decisions,$events,$ms,$sum, $ms*1e6/$events, $N/($ms/1000)*30/$DAYS);
