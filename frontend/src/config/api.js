// src/config/api.js
// -----------------------------------------------------------------------------
// Centralized API configuration for the frontend.
// Uses environment variables in production, with fallback to localhost for development.
// -----------------------------------------------------------------------------

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000';
const WS_BASE_URL = import.meta.env.VITE_WS_BASE_URL || 'ws://127.0.0.1:8080';

export const API = {
  baseURL: API_BASE_URL,
  wsBaseURL: WS_BASE_URL,
  
  // API endpoints
  endpoints: {
    login: `${API_BASE_URL}/api/login.php`,
    register: `${API_BASE_URL}/api/register.php`,
    logout: `${API_BASE_URL}/api/logout.php`,
    me: `${API_BASE_URL}/api/me.php`,
    nonce: `${API_BASE_URL}/api/nonce.php`,
    wsToken: `${API_BASE_URL}/api/ws_token.php`,
    challenges: `${API_BASE_URL}/api/challenges.php`,
    challenge: `${API_BASE_URL}/api/challenge.php`,
    challengeAccept: `${API_BASE_URL}/api/challenge_accept.php`,
    challengeResponse: `${API_BASE_URL}/api/challenge_response.php`,
    lobby: `${API_BASE_URL}/api/lobby.php`,
  },
  
  // WebSocket endpoints
  ws: {
    lobby: (token) => `${WS_BASE_URL}/lobby?token=${token}`,
  },
};

export default API;
