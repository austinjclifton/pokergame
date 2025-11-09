export const SUITS = ["hearts", "diamonds", "clubs", "spades"];
export const RANKS = ["A","2","3","4","5","6","7","8","9","10","J","Q","K"];

export function getSuitSymbol(suit) {
  switch (suit) {
    case "hearts": return "♥";
    case "diamonds": return "♦";
    case "clubs": return "♣";
    case "spades": return "♠";
    default: return "?";
  }
}

export function getSuitColor(suit) {
  return (suit === "hearts" || suit === "diamonds") ? "#C8102E" : "#000000";
}
