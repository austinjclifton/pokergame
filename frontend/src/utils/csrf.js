// src/utils/csrf.js
// -----------------------------------------------------------------------------
// CSRF token helper for frontend
// Fetches CSRF tokens from the backend for use in state-changing requests
// -----------------------------------------------------------------------------

import API from "../config/api";

/**
 * Fetch a CSRF token from the backend.
 * Tokens are bound to the current session and expire after 15 minutes.
 * 
 * @returns {Promise<string>} The CSRF token
 * @throws {Error} If token fetch fails
 */
export async function fetchCsrfToken() {
  const res = await fetch(API.endpoints.nonce, { credentials: "include" });
  const data = await res.json();
  
  if (!res.ok || !data.ok || !data.nonce) {
    throw new Error("Failed to retrieve CSRF token");
  }
  
  return data.nonce;
}

