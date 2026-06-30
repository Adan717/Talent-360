// @ts-nocheck
import React, { createContext, useContext } from 'react';

export const ClockContext2 = createContext<any>(null);

export function useClockContext2() {
  return useContext(ClockContext2);
}
