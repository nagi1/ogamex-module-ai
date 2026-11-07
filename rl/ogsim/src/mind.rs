//! Living minds (arm `minds`, SOCIAL-ENGINES-CONTRACT.md): each account keeps a mind - relations per other account,
//! episodic memories, mood and emotions, beliefs about partners - that the simulator holds and sends to the cognition
//! engines through the gateway (port 8099). The engines compute (FAtiMA appraisal, AgentOS recall, PsychSim partner
//! belief, CBRKit similar cases); the state travels in the request and comes back updated. Nothing here imitates an
//! engine: this module only keeps state, records what the account saw, and talks to the gateway.

use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::collections::BTreeMap;
use std::io::{Read, Write};
use std::net::TcpStream;
use std::time::Duration;

/// What one account feels toward another (updated by the engines).
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct Relation { pub trust: f64, pub liking: f64, pub fear: f64, pub respect: f64, pub debt: f64 }

/// One thing the account saw happen: only what a real player sees (attacks received, public treaty events, tribute,
/// help that arrived).
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Memory { pub id: u64, pub event: String, pub actor: usize, pub at: f64, pub valence: f64, pub weight: f64 }

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct Emotion { pub name: String, pub intensity: f64, pub since: f64 }

/// The engine-carried belief about one other account (PsychSim's state passes through untouched).
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct Belief { pub p_loyal: f64, pub state: Value }

/// AgentOS `/social/recall` aggregate toward one actor (sums of AgentOS's own memory strengths).
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct Recalled { pub grievance: f64, pub gratitude: f64, pub broken: f64, pub kept: f64 }

/// Memories a mind keeps to send (the most recent; AgentOS's consolidation decides what is still remembered).
pub const MEMORY_CAP: usize = 60;
/// The simulator's hour 0 as a Unix time, for the engines' clocks.
pub const EPOCH: f64 = 1_790_000_000.0;

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct Mind {
    pub mood: f64,
    pub emotions: Vec<Emotion>,
    pub relations: BTreeMap<usize, Relation>,
    pub memories: Vec<Memory>,
    pub beliefs: BTreeMap<usize, Belief>,
    /// AgentOS's recall per other account: what this mind still remembers of it.
    pub recalled: BTreeMap<usize, Recalled>,
    /// FAtiMA's character state (mood, emotions with age, relations, memories), returned by each call and sent back.
    pub fatima: Value,
    /// Events since the last engine pass, appraised at the next one.
    #[serde(skip)]
    pub fresh: Vec<Memory>,
}

#[derive(Clone, Debug, Default)]
pub struct Minds {
    pub on: bool,
    pub minds: Vec<Mind>,
    pub gateway: String,
    next_id: u64,
    /// Engine calls made and failed over the season.
    pub calls: u64,
    pub failures: u64,
}

impl Minds {
    pub fn new(on: bool, accounts: usize) -> Minds {
        let gateway = std::env::var("OGSIM_GATEWAY").unwrap_or_else(|_| "127.0.0.1:8099".into());
        Minds { on, minds: vec![Mind::default(); accounts], gateway, ..Minds::default() }
    }

    /// Records what `who` saw: `actor` did `event` (valence < 0 harm, > 0 help) at `hour`.
    pub fn saw(&mut self, who: usize, event: &str, actor: usize, hour: f64, valence: f64, weight: f64) {
        if !self.on || who == actor || who >= self.minds.len() { return }
        self.next_id += 1;
        self.minds[who].fresh.push(Memory { id: self.next_id, event: event.into(), actor, at: hour, valence, weight });
    }

    /// One batch through the gateway: `items` of {engine, route, body}; one result per item, `Value::Null` on error.
    pub fn batch(&mut self, items: Vec<Value>) -> Vec<Value> {
        let n = items.len();
        if n == 0 { return Vec::new() }
        self.calls += n as u64;
        match post(&self.gateway, "/v1/batch", &json!({ "items": items })) {
            Some(v) => {
                let results: Vec<Value> = v.get("results").and_then(Value::as_array).cloned().unwrap_or_default();
                let out: Vec<Value> = (0..n).map(|i| results.get(i).filter(|r| r.get("error").is_none()).cloned().unwrap_or(Value::Null)).collect();
                self.failures += out.iter().filter(|r| r.is_null()).count() as u64;
                out
            }
            None => { self.failures += n as u64; vec![Value::Null; n] }
        }
    }
}

impl Minds {
    /// AgentOS episodic recall for every mind that remembers something: fresh events join the memories, AgentOS
    /// encodes, decays, consolidates and ranks them with the persona's HEXACO traits, and the per-actor aggregate
    /// comes back into `recalled`. `traits` maps an account to its persona (None: neutral).
    pub fn recall(&mut self, hour: f64, traits: &dyn Fn(usize) -> Option<Value>) {
        let mut who = Vec::new();
        let mut items = Vec::new();
        for (a, mind) in self.minds.iter_mut().enumerate() {
            mind.memories.append(&mut mind.fresh);
            if mind.memories.len() > MEMORY_CAP { let cut = mind.memories.len() - MEMORY_CAP; mind.memories.drain(..cut); }
            if mind.memories.is_empty() { continue }
            let memories: Vec<Value> = mind.memories.iter().map(|m| {
                let mut tags = vec![m.event.clone()];
                if m.event == "betrayed" || m.event == "betrayal_exposed" { tags.push("promise_broken".into()) }
                if m.event == "pact" { tags.push("promise_kept".into()) }
                json!({"id": m.id, "text": format!("{} by {}", m.event.replace('_', " "), m.actor), "tags": tags,
                       "at": EPOCH + m.at * 3600.0, "valence": m.valence, "arousal": m.weight, "actor": m.actor.to_string()})
            }).collect();
            let mut item = json!({"scopeId": format!("account-{a}"), "query": "who did what to me", "now": EPOCH + hour * 3600.0, "memories": memories});
            if let Some(t) = traits(a) { item["traits"] = t }
            who.push(a);
            items.push(item);
        }
        // One gateway item per chunk of minds, so the gateway fans the chunks out over the AgentOS replicas.
        let chunk = 16;
        let calls: Vec<Value> = items.chunks(chunk).map(|c| json!({"engine": "agentos", "route": "/social/recall", "body": {"items": c}})).collect();
        let answers = self.batch(calls);
        for (k, answer) in answers.iter().enumerate() {
            let Some(results) = answer.get("results").and_then(Value::as_array) else { continue };
            for (j, r) in results.iter().enumerate() {
                let Some(&a) = who.get(k * chunk + j) else { continue };
                let Some(actors) = r.get("actors").and_then(Value::as_object) else { continue };
                let f = |v: &Value, k: &str| v.get(k).and_then(Value::as_f64).unwrap_or(0.0);
                self.minds[a].recalled = actors.iter().filter_map(|(actor, v)| Some((actor.parse().ok()?,
                    Recalled { grievance: f(v, "grievance"), gratitude: f(v, "gratitude"), broken: f(v, "brokenPromises"), kept: f(v, "keptPromises") }))).collect();
            }
        }
    }
}

/// Simulator memory event -> FAtiMA social event (`scenarios/ogame-social.json` `events`).
fn fatima_event(event: &str) -> Option<&'static str> {
    Some(match event {
        "attacked" | "revolt" => "attacked",
        "war_declared" | "pact_refused" => "threatened",
        "betrayed" => "betrayed",
        "pact" => "pact_offered",
        "peace" => "pact_kept",
        "submitted" => "helped",
        "dominated" => "tribute_demanded",
        "coup" | "overthrown" | "coup_failed" | "plot_foiled" => "coup",
        "betrayal_exposed" | "secret_pact_exposed" => "leak_heard",
        _ => return None,
    })
}

impl Minds {
    /// FAtiMA appraisal of what each of `who` saw since the last pass: its character state comes back with emotions
    /// (decayed by the toolkit's own half-lives), mood and relations moved. `persona(a)` gives {traits}.
    pub fn feel(&mut self, who: &[usize], hour: f64, persona: &dyn Fn(usize) -> Value) {
        let mut asked = Vec::new();
        let mut items = Vec::new();
        for &a in who {
            let events: Vec<Value> = self.minds[a].fresh.iter().filter_map(|m| fatima_event(&m.event).map(|e|
                json!({"event": e, "actor": m.actor.to_string(), "intensity": m.weight.clamp(0.0, 1.0), "tick": m.at}))).collect();
            if events.is_empty() { continue }
            let mut item = json!({"persona": persona(a), "events": events, "now": hour});
            if !self.minds[a].fatima.is_null() { item["state"] = self.minds[a].fatima.clone() }
            asked.push(a);
            items.push(json!({"engine": "fatima", "route": "/social/appraise", "body": {"items": [item]}}));
        }
        for (a, answer) in asked.into_iter().zip(self.batch(items)) {
            let Some(r) = answer.get("results").and_then(|r| r.get(0)).filter(|r| r.get("error").is_none()) else { continue };
            if let Some(state) = r.get("state") { self.minds[a].fatima = state.clone() }
            if let Some(m) = r.get("mood").and_then(Value::as_f64) { self.minds[a].mood = m }
        }
    }

    /// FAtiMA social exchange (CiF volitions over its emotions and relations): `(asker, other, context, options)`
    /// -> the chosen exchange, None when the engine did not answer.
    pub fn exchange(&mut self, questions: &[(usize, usize, Value, Vec<&str>)], hour: f64, persona: &dyn Fn(usize) -> Value) -> Vec<Option<String>> {
        let items: Vec<Value> = questions.iter().map(|(a, other, context, options)| {
            let mut item = json!({"persona": persona(*a), "other": other.to_string(), "context": context, "options": options, "now": hour});
            if !self.minds[*a].fatima.is_null() { item["state"] = self.minds[*a].fatima.clone() }
            json!({"engine": "fatima", "route": "/social/exchange", "body": {"items": [item]}})
        }).collect();
        self.batch(items).into_iter().map(|a| a.get("results").and_then(|r| r.get(0)).and_then(|r| r.get("choice")).and_then(Value::as_str).map(String::from)).collect()
    }
}

/// A plain HTTP/1.1 POST of JSON to the local gateway (no client crate: the gateway is on loopback).
pub fn post(addr: &str, path: &str, body: &Value) -> Option<Value> {
    let payload = body.to_string();
    let mut stream = TcpStream::connect(addr).ok()?;
    stream.set_read_timeout(Some(Duration::from_secs(600))).ok()?;
    let head = format!("POST {path} HTTP/1.1\r\nHost: {addr}\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
    stream.write_all(head.as_bytes()).ok()?;
    stream.write_all(payload.as_bytes()).ok()?;
    let mut raw = Vec::new();
    stream.read_to_end(&mut raw).ok()?;
    let text = String::from_utf8_lossy(&raw);
    let (headers, body) = text.split_once("\r\n\r\n")?;
    if !headers.starts_with("HTTP/1.1 200") && !headers.starts_with("HTTP/1.0 200") { return None }
    let body = if headers.to_ascii_lowercase().contains("transfer-encoding: chunked") { dechunk(body) } else { body.to_string() };
    serde_json::from_str(&body).ok()
}

fn dechunk(mut s: &str) -> String {
    let mut out = String::new();
    while let Some((size, rest)) = s.split_once("\r\n") {
        let Ok(n) = usize::from_str_radix(size.trim(), 16) else { break };
        if n == 0 || rest.len() < n { break }
        out.push_str(&rest[..n]);
        s = rest[n..].trim_start_matches("\r\n");
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_mind_records_only_what_its_owner_saw() {
        let mut m = Minds::new(true, 3);
        m.saw(0, "attacked", 1, 5.0, -1.0, 0.5);
        m.saw(0, "attacked", 0, 5.0, -1.0, 0.5);
        assert_eq!(m.minds[0].fresh.len(), 1);
        assert!(m.minds[1].fresh.is_empty() && m.minds[2].fresh.is_empty());
    }

    #[test]
    fn chunked_bodies_are_joined() {
        assert_eq!(dechunk("4\r\n{\"a\"\r\n3\r\n:1}\r\n0\r\n\r\n"), "{\"a\":1}");
    }

    /// Live: the gateway answers a batch (skipped when it is down).
    #[test]
    fn the_gateway_answers_a_batch() {
        let mut m = Minds::new(true, 1);
        if TcpStream::connect(&m.gateway).is_err() { return }
        let r = m.batch(vec![json!({"engine": "psychsim", "route": "/health", "body": {}})]);
        assert_eq!(r.len(), 1);
    }
}
