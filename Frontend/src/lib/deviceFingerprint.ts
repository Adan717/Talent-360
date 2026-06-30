export function getDeviceFingerprint(): string {
  let fp = localStorage.getItem('talent_device_fingerprint');
  
  if (!fp) {
    // Generate a secure pseudo-UUID for browser fingerprinting
    const userAgent = navigator.userAgent;
    const screenInfo = `${screen.width}x${screen.height}x${screen.colorDepth}`;
    const timezone = new Date().getTimezoneOffset();
    const randomSeed = Math.random().toString(36).substring(2, 15);
    
    // Combine browser characteristics with a random token
    const rawString = `${userAgent}|${screenInfo}|${timezone}|${randomSeed}`;
    
    // Simple hash function to generate a cleaner fingerprint string
    let hash = 0;
    for (let i = 0; i < rawString.length; i++) {
      const char = rawString.charCodeAt(i);
      hash = (hash << 5) - hash + char;
      hash |= 0; // Convert to 32bit integer
    }
    
    fp = `fp_${Math.abs(hash).toString(16)}_${randomSeed}`;
    localStorage.setItem('talent_device_fingerprint', fp);
  }
  
  return fp;
}
