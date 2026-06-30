// @ts-nocheck
import React, { createContext, useContext } from 'react';

export const ClockContext = createContext<any>(null);

export function useClockContext() {
  return useContext(ClockContext);
}
