// Same algorithm as econ.php, line for line.
const BASE: [[f64; 3]; 6] = [[60.,15.,1.5],[48.,24.,1.6],[225.,75.,1.5],[75.,30.,1.5],[1000.,0.,2.0],[1000.,500.,2.0]];
#[derive(Clone)]
pub struct Planet { pub l: [i32; 6], pub r: [f64; 3], pub t: f64, pub busy: i32, pub end: f64 }
fn cap(l: i32) -> f64 { 5000.0 * (2.5 * (20.0 * l as f64 / 33.0).exp()).floor() }
fn p11(c: f64, l: i32) -> f64 { c * (l as f64) * 1.1f64.powf(l as f64) }
pub fn make(n: usize) -> Vec<Planet> {
    let mut s: i64 = 12345;
    (0..n).map(|_| { s = (s * 1103515245 + 12345) & 0x7fffffff; Planet { l: [0;6], r: [500.,500.,0.], t: -40.0 + (s as f64 / 0x7fffffff as f64) * 120.0, busy: -1, end: 0.0 } }).collect()
}
pub fn advance(p: &mut Planet, horizon: f64) -> (u64, u64) {
    let (mut dec, mut ev) = (0u64, 0u64);
    let mut now = 0.0;
    while now < horizon {
        let l = p.l;
        let usage = p11(10.0,l[0]) + p11(10.0,l[1]) + p11(20.0,l[2]);
        let e = p11(20.0,l[3]);
        let f = if usage <= 0.0 { 1.0 } else { (e/usage).min(1.0) };
        let rate = [30.0 + p11(30.0,l[0])*f, 15.0 + p11(20.0,l[1])*f, p11(10.0,l[2])*(1.44-0.004*p.t)*f];
        let caps = [cap(l[4]), cap(l[5]), cap(0)];
        let next;
        if p.busy < 0 {
            dec += 1;
            let (mut best, mut best_eta) = (-1i32, f64::INFINITY);
            for k in 0..6 {
                if f < 0.999 && k != 3 { continue; }
                let fac = BASE[k][2].powf(l[k] as f64); let (cm, cc) = (BASE[k][0]*fac, BASE[k][1]*fac);
                if cm > caps[0] || cc > caps[1] { continue; }
                let eta = 0.0f64.max((cm-p.r[0])/rate[0]).max((cc-p.r[1])/rate[1]) * 3600.0;
                if eta < best_eta { best_eta = eta; best = k as i32; }
            }
            if best < 0 {
                best = if BASE[0][0]*BASE[0][2].powf(l[0] as f64) > caps[0] { 4 } else { 5 };
                let b = best as usize; let fac = BASE[b][2].powf(l[b] as f64);
                best_eta = 0.0f64.max((BASE[b][0]*fac-p.r[0])/rate[0]).max((BASE[b][1]*fac-p.r[1])/rate[1]) * 3600.0;
            }
            if best_eta <= 0.0 {
                let b = best as usize; let fac = BASE[b][2].powf(l[b] as f64); let (cm, cc) = (BASE[b][0]*fac, BASE[b][1]*fac);
                p.r[0] -= cm; p.r[1] -= cc; p.busy = best; p.end = now + 1.0f64.max((cm+cc)/2500.0*3600.0); next = p.end;
            } else { next = now + 1.0f64.max(best_eta); }
        } else { next = p.end; }
        let next = next.min(horizon);
        let h = (next - now) / 3600.0;
        for k in 0..3 { if p.r[k] < caps[k] { p.r[k] = caps[k].min(p.r[k] + rate[k]*h); } }
        now = next; ev += 1;
        if p.busy >= 0 && now >= p.end { p.l[p.busy as usize] += 1; p.busy = -1; }
    }
    (dec, ev)
}
pub fn checksum(ps: &[Planet]) -> i64 { ps.iter().map(|p| p.l.iter().map(|&x| x as i64).sum::<i64>()*1000 + p.r[0] as i64).sum() }

// C ABI for PHP FFI: advance n planets (state kept in Rust), return checksum.
#[no_mangle] pub extern "C" fn econ_run(n: u64, days: u64) -> i64 {
    let mut ps = make(n as usize); let h = days as f64 * 86400.0;
    for p in ps.iter_mut() { advance(p, h); } checksum(&ps)
}
// Per-call overhead probe: one tiny step per call.
#[no_mangle] pub extern "C" fn econ_noop(x: i64) -> i64 { x + 1 }
