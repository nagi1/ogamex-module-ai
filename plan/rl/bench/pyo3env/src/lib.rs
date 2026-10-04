use pyo3::prelude::*;
#[pyclass]
struct Env { ps: Vec<econrs::Planet>, obs: Vec<f32> }
#[pymethods]
impl Env {
    #[new] fn new(n: usize) -> Self { Env { ps: econrs::make(n), obs: vec![0.0; n * 64] } }
    /// one decision-level step for every env: advance each planet one day, write a 64-float obs row
    fn step_all(&mut self, days: f64) -> usize {
        for (i, p) in self.ps.iter_mut().enumerate() {
            econrs::advance(p, days * 86400.0);
            let row = &mut self.obs[i*64..i*64+64];
            for k in 0..6 { row[k] = p.l[k] as f32; } row[6] = p.r[0] as f32; row[7] = p.r[1] as f32;
        }
        self.ps.len()
    }
    fn noop(&self) -> usize { 1 }
    fn obs_len(&self) -> usize { self.obs.len() }
}
#[pymodule]
fn ogsim(m: &Bound<'_, PyModule>) -> PyResult<()> { m.add_class::<Env>()?; Ok(()) }
