/**
 * The fixture server command, shared by both suites: `php -S` by default,
 * php-fpm behind Caddy with `E2E_SERVER=fpm` (what CI runs).
 *
 * @see docs/internals/testing.md#the-fixture-server
 */
export function fixtureServer(port, sandbox) {
    const pub = `../../apps/${sandbox}/public`;
    const server = process.env.E2E_SERVER === 'fpm'
        ? `../../scripts/e2e-server.sh ${port} ${pub}`
        : `PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:${port} -t ${pub} ${pub}/router.php`;

    // Supervised either way. Workers outlive a crashed `php -S` and keep the
    // port: free it before starting again.
    return `while true; do ${server}; echo '[fixture] web server exited — restarting';`
        + ` pkill -f '^php -S 127.0.0.1:${port}'; sleep 0.3; done`;
}
