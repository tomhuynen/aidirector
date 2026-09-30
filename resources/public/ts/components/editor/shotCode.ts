/**
 * Shots are labelled SH010, SH020, ... from their position in the sequence.
 */
export const shotCode = (position: number) => `SH${String(position * 10).padStart(3, '0')}`
