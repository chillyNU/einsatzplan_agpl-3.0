<footer class="app-footer">
    <a href="impressum.php">Impressum</a>
    <?php if (isset($_SESSION['user_id'])): ?>
    |
    <a href="info.php">FAQ</a>
    <?php endif; ?>
    |
    <a href="https://github.com/chillyNU/einsatzplan_agpl-3.0" target="_blank" rel="noopener">Quellcode (AGPL-3.0)</a>
</footer>
