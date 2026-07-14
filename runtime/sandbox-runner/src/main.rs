//! JSON-lines bridge between the HaoCode PHP SDK and Tokimo's native sandbox.
//!
//! Stdout is reserved for protocol responses. Tokimo diagnostics remain on
//! stderr so PHP can parse responses without log filtering.

use std::io::{BufRead, Write};
use std::path::PathBuf;
use std::sync::mpsc::{Receiver, RecvTimeoutError};
use std::time::{Duration, Instant};

use base64::Engine;
use serde::{Deserialize, Serialize};
use tokimo_package_sandbox::{
    ConfigureParams, Event, JobId, Mount, NetworkPolicy, Sandbox, ShellOpts,
};

const PROTOCOL_VERSION: u32 = 1;
const MAX_OUTPUT_BYTES: usize = 4 * 1024 * 1024;

#[derive(Deserialize)]
#[serde(tag = "op", rename_all = "snake_case")]
enum Request {
    Start {
        protocol_version: u32,
        config: StartConfig,
    },
    Exec {
        command: String,
        cwd: String,
        timeout_ms: u64,
    },
    Shutdown,
}

#[derive(Deserialize)]
struct StartConfig {
    user_data_name: String,
    session_id: String,
    base_rootfs: PathBuf,
    vm_dir: PathBuf,
    workspace_host_path: PathBuf,
    remote_cwd: PathBuf,
    memory_mb: u64,
    cpu_count: u32,
    network: String,
}

#[derive(Serialize)]
struct Response {
    ok: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    error: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    backend: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    stdout_base64: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    stderr_base64: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    exit_code: Option<i32>,
    #[serde(skip_serializing_if = "Option::is_none")]
    timed_out: Option<bool>,
}

impl Response {
    fn ok() -> Self {
        Self {
            ok: true,
            error: None,
            backend: None,
            stdout_base64: None,
            stderr_base64: None,
            exit_code: None,
            timed_out: None,
        }
    }

    fn error(error: impl Into<String>) -> Self {
        Self {
            ok: false,
            error: Some(error.into()),
            ..Self::ok()
        }
    }
}

fn main() {
    if let Err(error) = run() {
        let _ = write_response(&Response::error(error));
        std::process::exit(1);
    }
}

fn run() -> Result<(), String> {
    let stdin = std::io::stdin();
    let mut lines = stdin.lock().lines();
    let first = lines
        .next()
        .ok_or_else(|| "runner expected a start request".to_string())?
        .map_err(|error| format!("read start request: {error}"))?;
    let request: Request =
        serde_json::from_str(&first).map_err(|error| format!("decode start request: {error}"))?;
    let Request::Start {
        protocol_version,
        config,
    } = request
    else {
        return Err("first runner request must be start".into());
    };
    if protocol_version != PROTOCOL_VERSION {
        return Err(format!(
            "unsupported protocol version {protocol_version}; expected {PROTOCOL_VERSION}"
        ));
    }

    let network = match config.network.as_str() {
        "blocked" => NetworkPolicy::Blocked,
        "allow-all" => NetworkPolicy::AllowAll,
        other => return Err(format!("unsupported network policy: {other}")),
    };
    let sandbox = Sandbox::connect().map_err(|error| format!("connect: {error}"))?;
    sandbox
        .configure(ConfigureParams {
            user_data_name: config.user_data_name,
            base_rootfs: config.base_rootfs,
            vm_dir: config.vm_dir,
            memory_mb: config.memory_mb,
            cpu_count: config.cpu_count,
            mounts: vec![Mount {
                name: "workspace".into(),
                host_path: config.workspace_host_path,
                guest_path: config.remote_cwd,
                read_only: false,
                create_host_dir: true,
            }],
            network,
            session_id: config.session_id,
            ..Default::default()
        })
        .map_err(|error| format!("configure: {error}"))?;
    let events = sandbox
        .subscribe()
        .map_err(|error| format!("subscribe: {error}"))?;
    sandbox
        .create_vm()
        .map_err(|error| format!("create VM: {error}"))?;
    sandbox
        .start_vm()
        .map_err(|error| format!("start VM: {error}"))?;

    let mut started = Response::ok();
    started.backend = Some(sandbox.active_backend().to_string());
    write_response(&started)?;

    for line in lines {
        let line = line.map_err(|error| format!("read request: {error}"))?;
        let request: Request = match serde_json::from_str(&line) {
            Ok(request) => request,
            Err(error) => {
                write_response(&Response::error(format!("decode request: {error}")))?;
                continue;
            }
        };

        match request {
            Request::Exec {
                command,
                cwd,
                timeout_ms,
            } => {
                let response = execute(&sandbox, &events, command, cwd, timeout_ms);
                write_response(&response)?;
            }
            Request::Shutdown => {
                let result = sandbox.stop_vm();
                let response = match result {
                    Ok(()) => Response::ok(),
                    Err(error) => Response::error(format!("stop VM: {error}")),
                };
                write_response(&response)?;
                return Ok(());
            }
            Request::Start { .. } => {
                write_response(&Response::error("runner is already started"))?;
            }
        }
    }

    sandbox
        .stop_vm()
        .map_err(|error| format!("stop VM: {error}"))
}

fn execute(
    sandbox: &Sandbox,
    events: &Receiver<Event>,
    command: String,
    cwd: String,
    timeout_ms: u64,
) -> Response {
    let shell = match sandbox.spawn_shell(ShellOpts {
        argv: Some(vec!["/bin/sh".into(), "-lc".into(), command]),
        cwd: Some(cwd),
        ..Default::default()
    }) {
        Ok(shell) => shell,
        Err(error) => return Response::error(format!("spawn command: {error}")),
    };

    collect_command(
        sandbox,
        events,
        &shell,
        Duration::from_millis(timeout_ms.max(1)),
    )
}

fn collect_command(
    sandbox: &Sandbox,
    events: &Receiver<Event>,
    shell: &JobId,
    timeout: Duration,
) -> Response {
    let deadline = Instant::now() + timeout;
    let mut stdout = Vec::new();
    let mut stderr = Vec::new();
    let mut exit_code = None;
    let mut timed_out = false;

    while Instant::now() < deadline {
        let remaining = deadline.saturating_duration_since(Instant::now());
        match events.recv_timeout(remaining.min(Duration::from_millis(250))) {
            Ok(Event::Stdout { id, data }) if id == *shell => append_capped(&mut stdout, &data),
            Ok(Event::Stderr { id, data }) if id == *shell => append_capped(&mut stderr, &data),
            Ok(Event::Exit {
                id,
                exit_code: code,
                ..
            }) if id == *shell => {
                exit_code = Some(code);
                break;
            }
            Ok(Event::Error { id, message, .. }) if id.as_ref() == Some(shell) => {
                append_capped(&mut stderr, message.as_bytes());
            }
            Ok(_) => {}
            Err(RecvTimeoutError::Timeout) => {}
            Err(RecvTimeoutError::Disconnected) => {
                append_capped(&mut stderr, b"sandbox event stream disconnected");
                break;
            }
        }
    }

    if exit_code.is_none() && Instant::now() >= deadline {
        timed_out = true;
        let _ = sandbox.signal_shell(shell, 15);
        std::thread::sleep(Duration::from_millis(100));
        let _ = sandbox.signal_shell(shell, 9);
        exit_code = Some(124);
    }
    let _ = sandbox.close_shell(shell);

    let encoder = base64::engine::general_purpose::STANDARD;
    let mut response = Response::ok();
    response.stdout_base64 = Some(encoder.encode(stdout));
    response.stderr_base64 = Some(encoder.encode(stderr));
    response.exit_code = exit_code;
    response.timed_out = Some(timed_out);
    response
}

fn append_capped(target: &mut Vec<u8>, chunk: &[u8]) {
    let remaining = MAX_OUTPUT_BYTES.saturating_sub(target.len());
    target.extend_from_slice(&chunk[..chunk.len().min(remaining)]);
}

fn write_response(response: &Response) -> Result<(), String> {
    let mut stdout = std::io::stdout().lock();
    serde_json::to_writer(&mut stdout, response)
        .map_err(|error| format!("encode response: {error}"))?;
    stdout
        .write_all(b"\n")
        .map_err(|error| format!("write response: {error}"))?;
    stdout
        .flush()
        .map_err(|error| format!("flush response: {error}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn append_capped_never_exceeds_protocol_output_limit() {
        let mut output = vec![b'a'; MAX_OUTPUT_BYTES - 2];

        append_capped(&mut output, b"12345");

        assert_eq!(output.len(), MAX_OUTPUT_BYTES);
    }

    #[test]
    fn error_response_serializes_as_failed_protocol_response() {
        let serialized = serde_json::to_value(Response::error("boom")).expect("serialize response");

        assert_eq!(
            serialized,
            serde_json::json!({"ok": false, "error": "boom"})
        );
    }
}
