# HaoCode sandbox runners

The Composer package includes only the small `hao-code-sandbox` PHP installer.
Native executables are release assets and are downloaded for the current host
only when the user runs `vendor/bin/hao-code-sandbox install`. Release staging
uses these names:

- `haocode-sandbox-darwin-arm64`
- `haocode-sandbox-linux-amd64`
- `haocode-sandbox-linux-arm64`
- `haocode-sandbox-windows-amd64.exe`

Windows releases also include `haocode-sandbox-svc-windows-amd64.exe`. Tokimo's Hyper-V
backend requires this SYSTEM service to be installed once with administrator
rights; the unprivileged runner then connects to its named pipe.

The executables all implement protocol version 1 from
`runtime/sandbox-runner/src/main.rs`. They link the pinned
`tokimo-package-sandbox` revision and communicate with PHP using one JSON object
per line over stdin/stdout. Diagnostic output must go to stderr.

VM kernel and rootfs artifacts are intentionally not stored here. They are much
larger than the runners and are passed separately as `baseRootfs`.

The cross-platform workflow builds the four runners and Windows service. A
published GitHub release uploads each executable and its `.sha256` sidecar as a
release asset. Native files under this directory are local release-staging
artifacts only: they are ignored by Git and excluded from Composer archives.
Run `php scripts/verify-sandbox-binaries.php` before publishing a release.
