// src/config/api.js
// Explicit variables support split deployments. Development defaults target the
// documented local servers; production defaults use the page's own origin.
const trimTrailingSlash = (value) => value.replace(/\/+$/, "");

const defaultApiBaseUrl = import.meta.env.DEV
  ? "http://localhost:8000"
  : window.location.origin;
const defaultWsBaseUrl = import.meta.env.DEV
  ? "ws://localhost:8080"
  : `${window.location.protocol === "https:" ? "wss:" : "ws:"}//${window.location.host}/ws`;

const API_BASE_URL = trimTrailingSlash(
  import.meta.env.VITE_API_BASE_URL || defaultApiBaseUrl,
);
const WS_BASE_URL = trimTrailingSlash(
  import.meta.env.VITE_WS_BASE_URL || defaultWsBaseUrl,
);

export const API = {
  baseURL: API_BASE_URL,
  wsBaseURL: WS_BASE_URL,

  endpoints: {
    login: `${API_BASE_URL}/api/login.php`,
    register: `${API_BASE_URL}/api/register.php`,
    logout: `${API_BASE_URL}/api/logout.php`,
    me: `${API_BASE_URL}/api/me.php`,
    nonce: `${API_BASE_URL}/api/nonce.php`,
    wsToken: `${API_BASE_URL}/api/ws_token.php`,
    challenges: `${API_BASE_URL}/api/challenges.php`,
    challengesPending: `${API_BASE_URL}/api/pending.php`,
    challenge: `${API_BASE_URL}/api/challenge.php`,
    challengeAccept: `${API_BASE_URL}/api/challenge_accept.php`,
    challengeResponse: `${API_BASE_URL}/api/challenge_response.php`,
    lobby: `${API_BASE_URL}/api/lobby.php`,
  },

  ws: {
    lobby: (token) => `${WS_BASE_URL}/lobby?token=${token}`,
    game: (tableId, token) =>
      `${WS_BASE_URL}/game?table_id=${tableId}&token=${token}`,
  },
};

export default API;
