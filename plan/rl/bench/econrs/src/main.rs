use std::time::Instant;
fn main() {
    let a: Vec<String> = std::env::args().collect();
    let n: usize = a.get(1).map(|s| s.parse().unwrap()).unwrap_or(10000);
    let days: f64 = a.get(2).map(|s| s.parse().unwrap()).unwrap_or(30.0);
    let threads: usize = a.get(3).map(|s| s.parse().unwrap()).unwrap_or(1);
    let mut ps = econrs::make(n);
    let t0 = Instant::now();
    let chunk = (n + threads - 1) / threads;
    let (dec, ev) = std::thread::scope(|s| {
        let hs: Vec<_> = ps.chunks_mut(chunk).map(|c| s.spawn(move || { let mut t=(0,0); for p in c.iter_mut() { let r = econrs::advance(p, days*86400.0); t.0+=r.0; t.1+=r.1; } t })).collect();
        hs.into_iter().map(|h| h.join().unwrap()).fold((0,0), |a,b| (a.0+b.0, a.1+b.1))
    });
    let ms = t0.elapsed().as_secs_f64()*1000.0;
    println!("rust N={} days={} threads={} decisions={} events={} time={:.1}ms checksum={} ns/event={:.0} planet-months/s={:.0}", n, days, threads, dec, ev, ms, econrs::checksum(&ps), ms*1e6/ev as f64, n as f64/(ms/1000.0)*30.0/days);
}
