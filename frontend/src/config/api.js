// src/config/api.js
// -----------------------------------------------------------------------------
// Centralized API + WebSocket configuration with automatic environment detection
// -----------------------------------------------------------------------------

const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL || "http://localhost:8000";

const IS_LOCAL =
  API_BASE_URL.includes("localhost") || API_BASE_URL.includes("127.0.0.1");

// LOCAL → ws://localhost:8080
// VM    → wss://pokergame.webdev.gccis.rit.edu/ws (from env)
const WS_BASE_URL = IS_LOCAL
  ? "ws://localhost:8080"
  : import.meta.env.VITE_WS_BASE_URL; // e.g. wss://pokergame.webdev.gccis.rit.edu/ws

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
