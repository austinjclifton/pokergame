import React from "react";
import Heart from "./suits/Heart";
import Diamond from "./suits/Diamond";
import Club from "./suits/Club";
import Spade from "./suits/Spade";
import CardBack from "./CardBack";

export default function Card({ suit = "hearts", rank = "A", revealed = true }) {
  const width = 100;
  const height = 150;

  if (!revealed) {
    return <CardBack width={width} height={height} />;
  }

  const suitComponents = {
    hearts: <Heart size={height * 0.24} />,
    diamonds: <Diamond size={height * 0.24} />,
    clubs: <Club size={height * 0.24} />,
    spades: <Spade size={height * 0.24} />,
  };

  const isRed = suit === "hearts" || suit === "diamonds";
  const color = isRed ? "#C8102E" : "#000000";
  const fontSize = height * 0.16;

  const marginX = width * 0.18;
  const marginTop = height * 0.20;
  const marginBottom = height * 0.20;

  return (
    <svg
      width={width}
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      xmlns="http://www.w3.org/2000/svg"
      className="playing-card"
      style={{
        borderRadius: "10px",
        boxShadow: "0 3px 8px rgba(0,0,0,0.5)",
        margin: "0 0.4rem",
      }}
    >

      {/* Everything inside goes into card-inner to block glow */}
      <g className="card-inner">

        {/* Card background */}
        <rect
          x="0"
          y="0"
          width={width}
          height={height}
          rx="8"
          ry="8"
          fill="#fff"
          stroke="#000"
          strokeWidth="0.5"
        />

        {/* Top-left rank */}
        <text
          x={marginX}
          y={marginTop}
          textAnchor="middle"
          fontFamily="Roboto Condensed"
          fontWeight="700"
          fontSize={fontSize}
          fill={color}
        >
          {rank}
        </text>

        {/* Bottom-right rank */}
        <text
          x={width - marginX}
          y={height - marginBottom}
          textAnchor="middle"
          fontFamily="Roboto Condensed"
          fontWeight="700"
          fontSize={fontSize}
          fill={color}
          transform={`rotate(180, ${width - marginX}, ${height - marginBottom})`}
        >
          {rank}
        </text>

        {/* Suit center */}
        <g
          transform={`translate(${width / 2 - height * 0.12}, ${height / 2 - height * 0.12})`}
          style={{ color }}
        >
          {suitComponents[suit]}
        </g>

      </g>
    </svg>
  );
}
