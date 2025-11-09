// src/components/RegisterForm.jsx
// -----------------------------------------------------------------------------
// Handles new user registration for PokerGame.
// Validates inputs client-side, fetches a CSRF nonce from the backend,
// and sends credentials securely to /api/register.php.
//
// The backend (PHP) verifies the nonce, hashes the password,
// creates a user, and sets an HttpOnly session cookie.
// -----------------------------------------------------------------------------

import React, { useState } from "react";
import API from "../config/api";

export default function RegisterForm({ onRegister }) {
  // Form state
  const [form, setForm] = useState({
    username: "",
    email: "",
    password: "",
    confirm: "",
  });

  const [error, setError] = useState("");
  const [disabled, setDisabled] = useState(false);

  // Basic sanitizer for angle brackets
  const sanitize = (str) => str.replace(/[<>]/g, "").trim();

  // Handle typing changes
  const handleChange = (e) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    const username = sanitize(form.username);
    const email = sanitize(form.email);
    const password = sanitize(form.password);
    const confirm = sanitize(form.confirm);

    // -------------------- Client-side validation --------------------
    if (!username || !email || !password || !confirm) {
      setError("Please fill in all required fields.");
      return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
      setError("Please enter a valid email address.");
      return;
    }

    if (password !== confirm) {
      setError("Passwords do not match.");
      return;
    }

    if (password.length < 8) {
      setError("Password must be at least 8 characters long.");
      return;
    }

    setError("");
    setDisabled(true);

    try {
      // 1. Get a CSRF nonce from backend (ties to a session_id cookie)
      const nonceRes = await fetch(API.endpoints.nonce, { credentials: "include" });
      const nonceData = await nonceRes.json();
      const token = nonceData?.nonce;

      if (!token) throw new Error("Failed to retrieve nonce");

      // 2. Send registration request
      const res = await fetch(API.endpoints.register, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include", // include cookie set by nonce.php
        body: JSON.stringify({ username, email, password, token }),
      });

      const data = await res.json();

      if (!res.ok || !data.ok) {
        throw new Error(data.error || "Registration failed");
      }

      // 3. Notify parent (AuthGate or RegisterPage)
      onRegister?.(data.user);
    } catch (err) {
      setError(err.message || "Registration failed. Please try again.");
    } finally {
      setDisabled(false);
    }
  };

  // -------------------- Render --------------------
  return (
    <form onSubmit={handleSubmit} autoComplete="off">
      <label htmlFor="username" className="login-label">Username *</label>
      <input
        id="username"
        name="username"
        type="text"
        className="login-field"
        value={form.username}
        onChange={handleChange}
        autoComplete="username"
      />

      <label htmlFor="email" className="login-label">Email *</label>
      <input
        id="email"
        name="email"
        type="email"
        className="login-field"
        value={form.email}
        onChange={handleChange}
        autoComplete="email"
      />

      <label htmlFor="password" className="login-label">Password *</label>
      <input
        id="password"
        name="password"
        type="password"
        className="login-field"
        value={form.password}
        onChange={handleChange}
        autoComplete="new-password"
      />

      <label htmlFor="confirm" className="login-label">Confirm Password *</label>
      <input
        id="confirm"
        name="confirm"
        type="password"
        className="login-field"
        value={form.confirm}
        onChange={handleChange}
        autoComplete="new-password"
      />

      <button type="submit" className="login-button btn" disabled={disabled}>
        {disabled ? "Registering..." : "Register"}
      </button>

      {error && <p className="error-message">{error}</p>}
    </form>
  );
}
