// src/components/LoginForm.jsx
// -----------------------------------------------------------------------------
// Login form for PokerGame.
// Sends username/password to backend (/api/login.php), which validates credentials
// and sets an HttpOnly session cookie.
//
// This component only performs minimal sanitization. Authentication,
// password hashing, and SQL safety occur entirely in the backend.
// -----------------------------------------------------------------------------

import React, { useState } from "react";
import API from "../config/api";

export default function LoginForm({ onLogin }) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [disabled, setDisabled] = useState(false);

  // Remove angle brackets; prevents accidental HTML injection in controlled inputs
  const sanitize = (str) => str.replace(/[<>]/g, "").trim();

  const handleSubmit = async (e) => {
    e.preventDefault();

    const u = sanitize(username);
    const p = sanitize(password);

    if (!u || !p) {
      setError("Please fill in both fields.");
      return;
    }

    setDisabled(true);
    setError("");

    try {
      const res = await fetch(API.endpoints.login, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({ username: u, password: p }),
      });

      // Safely parse JSON (avoids crashing if backend sends HTML error page)
      const data = await res.json().catch(() => ({}));

      // Standard backend error shape: { ok:false, error: "...message..." }
      if (!res.ok || !data.ok) {
        throw new Error(data.error || "Invalid username or password.");
      }

      // Notify parent (LoginPage)
      onLogin?.(data.user);

    } catch (err) {
      // Normalized login failure message — never reveals server internals
      setError("Invalid username or password.");
    } finally {
      setDisabled(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} autoComplete="off">
      {/* Username field */}
      <label htmlFor="username" className="login-label">Username</label>
      <input
        id="username"
        type="text"
        className="login-field input"
        value={username}
        onChange={(e) => setUsername(e.target.value)}
        placeholder="e.g., admin"
        autoComplete="username"
      />

      {/* Password field */}
      <label htmlFor="password" className="login-label">Password</label>
      <input
        id="password"
        type="password"
        className="login-field input"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        placeholder="Your password"
        autoComplete="current-password"
      />

      {/* Submit */}
      <div className="login-actions">
        <button type="submit" className="login-button btn" disabled={disabled}>
          {disabled ? "Logging in..." : "Login"}
        </button>
      </div>

      {/* Error banner */}
      {error && <p className="error-message">{error}</p>}
    </form>
  );
}
