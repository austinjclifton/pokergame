// src/components/LoginForm.jsx
// -----------------------------------------------------------------------------
// Handles user login for PokerGame.
// Sends username/password to backend (/api/login.php), which validates credentials
// and creates a secure session (sets HttpOnly cookie).
//
// This form uses controlled React inputs and only sanitizes minimal characters
// to prevent HTML injection. Real validation (auth, SQL safety, password hash)
// occurs on the backend.
// -----------------------------------------------------------------------------

import React, { useState } from "react";
import API from "../config/api";

export default function LoginForm({ onLogin }) {
  // Form field state
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [disabled, setDisabled] = useState(false);

  // Basic sanitizer to strip < and > (prevents accidental HTML injection)
  const sanitize = (str) => str.replace(/[<>]/g, "").trim();

  const handleSubmit = async (e) => {
    e.preventDefault();

    // Trim and sanitize user input
    const u = sanitize(username);
    const p = sanitize(password);

    if (!u || !p) {
      setError("Please fill in both fields.");
      return;
    }

    setDisabled(true);
    setError("");

    try {
      // Send credentials to backend (PHP)
      // `credentials: "include"` ensures the session cookie is saved and sent automatically.
      const res = await fetch(API.endpoints.login, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({ username: u, password: p }),
      });

      const data = await res.json();

      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Login failed");
      }

      // Notify parent component (e.g., AuthGate or page) of successful login
      onLogin?.(data.user);
    } catch (err) {
      setError("Invalid username or password.");
    } finally {
      setDisabled(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} autoComplete="off">
      {/* Username field */}
      <label htmlFor="username" className="login-label">
        Username
      </label>
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
      <label htmlFor="password" className="login-label">
        Password
      </label>
      <input
        id="password"
        type="password"
        className="login-field input"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        placeholder="Your password"
        autoComplete="current-password"
      />

      {/* Submit button */}
      <div className="login-actions">
        <button type="submit" className="login-button btn" disabled={disabled}>
          {disabled ? "Logging in..." : "Login"}
        </button>
      </div>

      {/* Error display */}
      {error && <p className="error-message">{error}</p>}
    </form>
  );
}
