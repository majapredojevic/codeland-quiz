import http from 'k6/http';
import { check } from 'k6';

const manifest = JSON.parse(open(__ENV.MANIFEST_PATH));
const credentials = JSON.parse(open(__ENV.CREDENTIALS_PATH));
const baseUrl = __ENV.TARGET_URL.replace(/\/$/, '');

export const options = {
  vus: 1,
  iterations: 1,
  insecureSkipTLSVerify: manifest.configuration.localSelfSignedCertificate === true,
  thresholds: { http_req_failed: ['rate==0'] },
};

export default function () {
  const root = http.get(`${baseUrl}/`, { tags: { warmup: 'true', endpoint: 'frontend' } });
  const health = http.get(`${baseUrl}/health`, { tags: { warmup: 'true', endpoint: 'health' } });
  const ready = http.get(`${baseUrl}/ready`, { tags: { warmup: 'true', endpoint: 'ready' } });
  const preview = http.get(`${baseUrl}/api/game/session/${manifest.sessions[0].gamePin}`, {
    tags: { warmup: 'true', endpoint: 'preview' },
  });
  const login = http.post(
    `${baseUrl}/api/auth/login`,
    JSON.stringify({
      email: credentials.teachers[0].email,
      password: credentials.teachers[0].password,
    }),
    { headers: { 'Content-Type': 'application/json' }, tags: { warmup: 'true', endpoint: 'login' } },
  );
  check(root, { 'frontend warmed': (response) => response.status === 200 });
  check(health, { 'health warmed': (response) => response.status === 200 });
  check(ready, { 'readiness warmed': (response) => response.status === 200 });
  check(preview, { 'preview warmed': (response) => response.status === 200 });
  check(login, { 'teacher authentication warmed': (response) => response.status === 200 });
}
