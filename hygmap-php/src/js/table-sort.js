/**
 * Table sorting functionality for star table
 * Click on any column header to sort the table by that column
 */
document.addEventListener("DOMContentLoaded", function () {
  const table = document.getElementById("star-table");
  if (!table) return;

  const thead = table.querySelector("thead");
  const tbody = table.querySelector("tbody");
  if (!thead || !tbody) return;

  const headers = thead.querySelectorAll("th");
  let currentSortCol = null;
  let sortDir = 1; // 1 = ascending, -1 = descending

  headers.forEach((header, i) => {
    header.style.cursor = "pointer";
    header.setAttribute("role", "button");
    header.setAttribute("aria-sort", "none");

    header.addEventListener("click", () => {
      const rows = Array.from(tbody.querySelectorAll("tr"));

      // Determine sort direction
      if (currentSortCol === i) {
        sortDir = -sortDir; // toggle direction
      } else {
        sortDir = 1;
        if (currentSortCol !== null) {
          headers[currentSortCol].textContent = headers[currentSortCol].textContent.replace(/[\u2191\u2193]$/, '');
          headers[currentSortCol].setAttribute("aria-sort", "none");
        }
        currentSortCol = i;
      }

      // Sort rows
      rows.sort((a, b) => {
        const cellA = a.children[i].textContent.trim();
        const cellB = b.children[i].textContent.trim();

        const numA = parseFloat(cellA.replace(/[^-.\d]/g, ''));
        const numB = parseFloat(cellB.replace(/[^-.\d]/g, ''));

        const valA = isNaN(numA) ? cellA.toLowerCase() : numA;
        const valB = isNaN(numB) ? cellB.toLowerCase() : numB;

        return valA > valB ? sortDir : valA < valB ? -sortDir : 0;
      });

      // Apply sorted rows using DocumentFragment for better performance
      const fragment = document.createDocumentFragment();
      rows.forEach(row => fragment.appendChild(row));
      tbody.appendChild(fragment);

      // Add sort arrow and aria-sort to current header
      const arrow = sortDir === 1 ? ' ↑' : ' ↓';
      const ariaSort = sortDir === 1 ? 'ascending' : 'descending';
      headers[i].textContent = headers[i].textContent.replace(/[\u2191\u2193]$/, '') + arrow;
      headers[i].setAttribute("aria-sort", ariaSort);
    });
  });
});
