<?php
// api/download.php - 修复版 (增加路径检查和下载计数)
require '../config/db.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    // 1. 查询文件信息
    $sql = "SELECT * FROM resources WHERE id = $id";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $file_name = $row['file_path']; // 数据库里存的文件名 (例如 res_1739...pdf)
        $real_title = $row['title'] . "." . strtolower($row['type']); // 下载时显示的友好文件名
        
        // ⚠️ 关键修复：确保路径指向 uploads 文件夹
        $file_path = "../uploads/" . $file_name;

        // 2. 检查文件是否存在
        if (file_exists($file_path)) {
            // 3. 更新下载次数
            $conn->query("UPDATE resources SET downloads = downloads + 1 WHERE id = $id");

            // 4. 发送文件头
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . urlencode($real_title) . '"'); // 使用中文名下载
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file_path));
            
            // 5. 输出文件内容
            readfile($file_path);
            exit;
        } else {
            echo "<script>alert('文件不存在，可能已被管理员删除。路径: $file_path'); history.back();</script>";
        }
    } else {
        echo "<script>alert('资源记录不存在'); history.back();</script>";
    }
} else {
    echo "参数错误";
}
$conn->close();
?>