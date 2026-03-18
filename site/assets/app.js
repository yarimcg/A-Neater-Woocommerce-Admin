(() => {
  const root = document.documentElement;
  const btn = document.querySelector("[data-theme-toggle]");
  if (!btn) return;

  const storageKey = "aneater_site_theme";

  const getPreferred = () => {
    const saved = localStorage.getItem(storageKey);
    if (saved === "light" || saved === "dark") return saved;
    return null;
  };

  const apply = (mode) => {
    if (!mode) {
      root.removeAttribute("data-theme");
      return;
    }
    root.setAttribute("data-theme", mode);
  };

  const current = getPreferred();
  if (current) apply(current);

  btn.addEventListener("click", () => {
    const mode = root.getAttribute("data-theme");
    const next = mode === "dark" ? "light" : "dark";
    apply(next);
    localStorage.setItem(storageKey, next);
  });
})();
