// src/pages/LoginPage.jsx
// -----------------------------------------------------------------------------
// Login page for PokerGame.
// Wraps the LoginForm component and handles redirect after a successful login.
// The backend creates a secure PHP session (via cookie), so no client-side
// token storage is needed.
// -----------------------------------------------------------------------------

import React from "react";
import { useNavigate, Link } from "react-router-dom";
import LoginForm from "../components/LoginForm";
import "../styles/login.css";

export default function LoginPage({ onLogin }) {
  const navigate = useNavigate();

  // Called when LoginForm reports successful authentication
  const handleLogin = (user) => {

    // Notify parent (App.jsx or AuthGate) if needed
    onLogin?.(user);

    // Redirect to lobby — session cookie is already set by backend
    setTimeout(() => navigate("/lobby"), 200);
  };

  return (
    <div className="login-page">
      {/* Application title */}
      <h1 className="login-title">PokerGame</h1>

      {/* Login form that connects to /api/login.php */}
      <LoginForm onLogin={handleLogin} />

      {/* Navigation for new users */}
      <p className="login-meta">
        Don’t have an account?{" "}
        <Link to="/register">Register</Link>
      </p>
    </div>
  );
}
