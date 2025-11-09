import React from "react";

export default function Club({ size = 24 }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 100 100"
      xmlns="http://www.w3.org/2000/svg"
      fill="currentColor"
    >
      <circle cx="50" cy="25" r="18" />
      <circle cx="30" cy="50" r="18" />
      <circle cx="70" cy="50" r="18" />
      <rect x="45" y="40" width="10" height="45" />
    </svg>
  );
}
