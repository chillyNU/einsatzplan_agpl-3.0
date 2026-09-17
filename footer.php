<footer class="app-footer">
    <a href="impressum.php">Impressum</a>
    <?php if (isset($_SESSION['user_id'])): ?>
    |
    <a href="info.php">FAQ</a>
    <?php endif; ?>
</footer>
