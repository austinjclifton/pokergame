// src/pages/RegisterPage.jsx
// -----------------------------------------------------------------------------
// Registration page for PokerGame.
// Wraps RegisterForm and redirects to login after successful registration.
//
// The backend creates the session cookie after registration, so the user is
// immediately authenticated. The login page will display a success banner when
// redirected with ?registered=1.
// -----------------------------------------------------------------------------

import React from "react";
import { Link, useNavigate } from "react-router-dom";
import RegisterForm from "../components/RegisterForm";
import "../styles/login.css";

export default function RegisterPage() {
  const navigate = useNavigate();

  // Called when RegisterForm completes registration successfully
  const handleRegister = () => {
    // Redirect to login with success flag → banner displayed in LoginPage
    setTimeout(() => {
      navigate("/login?registered=1");
    }, 200);
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
