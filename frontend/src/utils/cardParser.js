// frontend/src/utils/cardParser.js
/**
 * Parse card string from backend format (e.g., "AS", "KD", "2C", "TH")
 * to format expected by Card component (suit: "hearts", rank: "A")
 */

const SUIT_MAP = {
  S: "spades",
  H: "hearts",
  D: "diamonds",
  C: "clubs",
};

/**
 * Parse a card string like "AS" or "KD" into { suit, rank }
 * @param {string} cardStr - Card string from backend (e.g., "AS", "KD", "TH")
 * @returns {{ suit: string, rank: string }|null}
 */
export function parseCard(cardStr) {
  if (!cardStr || typeof cardStr !== "string" || cardStr.length < 2) {
    return null;
  }

  const suitChar = cardStr.slice(-1).toUpperCase();
  const rankStr = cardStr.slice(0, -1).toUpperCase();

  const suit = SUIT_MAP[suitChar];
  if (!suit) {
    return null;
  }

  // Map T to 10 for display
  const rank = rankStr === "T" ? "10" : rankStr;

  return { suit, rank };
}

/**
 * Parse multiple card strings
 * @param {string[]} cardStrs - Array of card strings
 * @returns {Array<{ suit: string, rank: string }>}
 */
export function parseCards(cardStrs) {
  if (!Array.isArray(cardStrs)) {
    return [];
  }
  return cardStrs.map(parseCard).filter(Boolean);
}

