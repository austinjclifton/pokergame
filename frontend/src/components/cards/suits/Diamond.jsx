import React from "react";

export default function Diamond({ size = 24 }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 100 100"
      xmlns="http://www.w3.org/2000/svg"
      fill="currentColor"
    >
      <polygon points="50,5 80,50 50,95 20,50" />
    </svg>
  );
}
