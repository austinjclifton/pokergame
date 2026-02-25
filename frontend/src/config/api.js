// src/config/api.js

const envApi = import.meta.env.VITE_API_BASE_URL;
const envWs = import.meta.env.VITE_WS_BASE_URL;

const isBrowser = typeof window !== "undefined";
const origin = isBrowser ? window.location.origin : "";
const host = isBrowser ? window.location.host : "";

// In production: same-origin (https://pokergame.studio)
// In local dev: can still override with VITE_API_BASE_URL
const rawApi = envApi || origin || "http://localhost:8000";
const API_BASE_URL = rawApi.replace(/\/+$/, "").replace(/\/api$/, "");

// In production: wss://pokergame.studio/ws
// In local dev: ws://localhost:8080 unless overridden
const WS_BASE_URL =
  envWs ||
  (isBrowser
    ? `${window.location.protocol === "https:" ? "wss" : "ws"}://${host}/ws`
    : "ws://localhost:8080");

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
    // If your Ratchet server expects /lobby and /game paths, keep this:
    lobby: (token) => `${WS_BASE_URL}/lobby?token=${token}`,
    game: (tableId, token) =>
      `${WS_BASE_URL}/game?table_id=${tableId}&token=${token}`,
  },
};

export default API;
