// src/pages/RegisterPage.jsx
// -----------------------------------------------------------------------------
// Registration page for PokerGame.
// Wraps RegisterForm and handles redirect after successful registration.
//
// The backend (PHP) automatically creates a session and cookie after
// registration, so we can immediately navigate to /lobby.
// -----------------------------------------------------------------------------

import React from "react";
import { Link, useNavigate } from "react-router-dom";
import RegisterForm from "../components/RegisterForm";
import "../styles/login.css";

export default function RegisterPage() {
  const navigate = useNavigate();

  // Handle callback from RegisterForm when registration succeeds
  const handleRegister = (data) => {

    // Optional: show a quick success message, then redirect
    setTimeout(() => {
      navigate("/lobby"); // User is already logged in (session cookie set)
    }, 300);
  };

  return (
    <div className="login-page">
      {/* App title */}
      <h1 className="login-title">PokerGame</h1>

      {/* Registration form */}
      <RegisterForm onRegister={handleRegister} />

      {/* Navigation link for existing users */}
      <p className="login-meta">
        Already have an account?{" "}
        <Link to="/login">Login</Link>
      </p>
    </div>
  );
}
