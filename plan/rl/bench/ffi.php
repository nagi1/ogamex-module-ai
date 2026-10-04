<?php
$ffi = FFI::cdef("int64_t econ_run(uint64_t n, uint64_t days); int64_t econ_noop(int64_t x);", __DIR__.'/libeconrs.so');
$t=hrtime(true); $x=0; for($i=0;$i<1000000;$i++) $x=$ffi->econ_noop($x); printf("FFI noop: %.0f ns/call\n", (hrtime(true)-$t)/1e6);
$t=hrtime(true); $c=$ffi->econ_run(10000,30); printf("FFI econ_run 10000 planets x 30d: %.1f ms checksum=%d\n", (hrtime(true)-$t)/1e6, $c);
// Battle engine: realistic raid sizes through the host's JSON FFI boundary
$b = FFI::cdef("char* fight_battle_rounds(const char* i); void free_battle_result(char* p);", __DIR__.'/rust-libs/libbattle_engine_ffi.so');
function unit($id,$n,$a,$s,$h,$rf=[]){return ['unit_id'=>$id,'amount'=>$n,'attack_power'=>$a,'shield_points'=>$s,'hull_plating'=>$h,'rapidfire'=>(object)$rf];}
foreach ([[50,20,100],[500,200,1000],[5000,2000,10000],[100000,40000,200000]] as [$lf,$hf,$rl]) {
  $in = json_encode(['attacker_fleets'=>[['fleet_mission_id'=>1,'owner_id'=>1,'units'=>(object)['204'=>unit(204,$lf,50,10,400,['210'=>5,'212'=>5]),'205'=>unit(205,$hf,150,25,1000,['202'=>3])]]],
    'defender_fleets'=>[['fleet_mission_id'=>0,'owner_id'=>2,'units'=>(object)['401'=>unit(401,$rl,80,20,200),'402'=>unit(402,(int)($rl/2),100,25,200)]]],'seed'=>42]);
  $reps = $lf>50000?3:($lf>1000?20:500); $t=hrtime(true); $bytes=0;
  for($i=0;$i<$reps;$i++){ $p=$b->fight_battle_rounds($in); $s=FFI::string($p); $b->free_battle_result($p); $bytes=strlen($s); $d=json_decode($s,true);}
  printf("battle %d LF+%d HF vs %d RL+%d LL: %.3f ms/battle (in %d B, out %d B, rounds %d)\n",$lf,$hf,$rl,$rl/2,(hrtime(true)-$t)/1e6/$reps,strlen($in),$bytes,count($d['rounds']));
}
