<?php
include('../../db_pdo.php');

// 開啟錯誤報告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 每頁顯示的資料筆數
$limit = 12;

// 當前頁碼
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;

// 計算查詢資料的起始位置
$offset = ($page - 1) * $limit;

// 初始化篩選器變量
$filter = "";

// SQL 查詢語句，獲取總資料筆數，包含 JOIN 關係
$sql_count = "SELECT COUNT(*) 
              FROM product_list p
              JOIN color c ON p.id = c.product_id
              JOIN brand b ON p.brand_id = b.id
              JOIN main_category mc ON p.main_category_id = mc.id
              JOIN sub_category sc ON p.sub_category_id = sc.id
              WHERE 1=1"; // 使用 1=1 簡化後續條件的追加

// 關鍵字過濾
if (isset($_GET['keyword']) && !empty($_GET['keyword'])) {
    $keyword = '%' . $_GET['keyword'] . '%';
    $price_keyword = $_GET['keyword'];
    // 如果 keyword 是數字，嘗試搜索價格
    if (is_numeric($_GET['keyword'])) {
        $sql_count .= " AND (p.product_name LIKE :keyword 
                            OR b.name LIKE :keyword 
                            OR mc.name LIKE :keyword 
                            OR sc.name LIKE :keyword
                            OR c.color LIKE :keyword 
                            OR p.price = :price_keyword)";
    } else {
        $sql_count .= " AND (p.product_name LIKE :keyword 
                            OR b.name LIKE :keyword 
                            OR mc.name LIKE :keyword 
                            OR sc.name LIKE :keyword
                            OR c.color LIKE :keyword)";
    }
}

// 價格篩選
if (isset($_GET['price_filter']) && !empty($_GET['price_filter'])) {
    $filter = $_GET['price_filter'];
    if ($filter == 'under_1000') {
        $sql_count .= " AND p.price < 1000";
    } elseif ($filter == '1000_2000') {
        $sql_count .= " AND p.price BETWEEN 1000 AND 2000";
    } elseif ($filter == 'above_2000') {
        $sql_count .= " AND p.price > 2000";
    }
}

// 執行計算總數的查詢
$stmt = $pdo->prepare($sql_count);
if (isset($keyword)) {
    $stmt->bindParam(':keyword', $keyword, PDO::PARAM_STR);
    if (is_numeric($_GET['keyword'])) {
        $stmt->bindParam(':price_keyword', $price_keyword, PDO::PARAM_STR); // 绑定 price_keyword
    }
}
$stmt->execute();
$totalItems = $stmt->fetchColumn();

// 計算總頁數
$totalPages = ceil($totalItems / $limit);

// 每組顯示 5 個頁碼
$pagesPerGroup = 5;

// 計算當前頁碼所在的頁碼組
$currentGroup = ceil($page / $pagesPerGroup);

// 當前組的起始和結束頁碼
$startPage = ($currentGroup - 1) * $pagesPerGroup + 1;
$endPage = min($currentGroup * $pagesPerGroup, $totalPages);

// 獲取資料的查詢語句
$sql = "SELECT p.id, p.product_name, b.name AS brand_name, mc.name AS main_category_name, 
               sc.name AS sub_category_name, c.id AS color_id, c.color AS color_name, p.price, 
               c.mainimage
        FROM product_list p
        JOIN brand b ON p.brand_id = b.id
        JOIN main_category mc ON p.main_category_id = mc.id
        JOIN sub_category sc ON p.sub_category_id = sc.id
        JOIN color c ON p.id = c.product_id
        WHERE 1=1"; // 初始的 where 條件

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    // 接收表單數據
    $product_name = $_POST['product_name'];
    $brand_id = $_POST['brand_id'];
    $main_category_id = $_POST['main_category_id'];
    $sub_category_id = $_POST['sub_category_id'];
    $color = $_POST['color'];
    $price = $_POST['price'];
    $description = $_POST['description'];
    $images = $_FILES['images'];
    $stock = 1;  // 默認庫存量
    $valid = 1;  // 默認設置為有效

    try {
        // 開啟一個事務
        $pdo->beginTransaction();

        // 插入到 product_list 表格
        $sql_product = "INSERT INTO product_list (product_name, brand_id, main_category_id, sub_category_id, price, description, valid) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt_product = $pdo->prepare($sql_product);
        $stmt_product->execute([$product_name, $brand_id, $main_category_id, $sub_category_id, $price, $description, $valid]);

        // 獲取剛剛插入的 product_list 記錄的 ID
        $product_id = $pdo->lastInsertId();

        // 處理圖片上傳並插入到 color 表格
        if (!empty($images['name'][0])) {
            $upload_dir = './uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);  // 如果目錄不存在，創建目錄
            }

            foreach ($images['name'] as $key => $image_name) {
                $image_name = basename($image_name);
                $upload_file = $upload_dir . $image_name;

                if (move_uploaded_file($images['tmp_name'][$key], $upload_file)) {
                    // 成功上傳，將記錄插入 color 表格
                    $sql_color = "INSERT INTO color (product_id, color, mainimage, stock, valid) 
                                  VALUES (?, ?, ?, ?, ?)";
                    $stmt_color = $pdo->prepare($sql_color);
                    $stmt_color->execute([$product_id, $color, $image_name, $stock, $valid]);
                } else {
                    throw new Exception("圖片上傳失敗: " . $upload_file);
                }
            }
        }

        // 提交事務
        $pdo->commit();

        // 重定向到商品列表頁面
        header("Location: product_list.php");
        exit();
    } catch (Exception $e) {
        // 如果有錯誤，回滾事務
        $pdo->rollBack();
        echo "新增商品失敗: " . $e->getMessage();
    }
}

// 加入關鍵字搜尋條件
if (isset($_GET['keyword']) && !empty($_GET['keyword'])) {
    if (is_numeric($_GET['keyword'])) {
        $sql .= " AND (p.product_name LIKE :keyword 
                       OR b.name LIKE :keyword 
                       OR mc.name LIKE :keyword 
                       OR sc.name LIKE :keyword
                       OR c.color LIKE :keyword 
                       OR p.price = :price_keyword)";
    } else {
        $sql .= " AND (p.product_name LIKE :keyword 
                       OR b.name LIKE :keyword 
                       OR mc.name LIKE :keyword 
                       OR sc.name LIKE :keyword
                       OR c.color LIKE :keyword)";
    }
}

// 獲取品牌選項
$brands_sql = "SELECT id, name FROM brand";
$brands_stmt = $pdo->prepare($brands_sql);
$brands_stmt->execute();
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

// 獲取主要分類選項
$main_categories_sql = "SELECT id, name FROM main_category";
$main_categories_stmt = $pdo->prepare($main_categories_sql);
$main_categories_stmt->execute();
$main_categories = $main_categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// 加入價格篩選條件
if (isset($_GET['price_filter']) && !empty($_GET['price_filter'])) {
    if ($filter == 'under_1000') {
        $sql .= " AND p.price < 1000";
    } elseif ($filter == '1000_2000') {
        $sql .= " AND p.price BETWEEN 1000 AND 2000";
    } elseif ($filter == 'above_2000') {
        $sql .= " AND p.price > 2000";
    }
}

$sql .= " ORDER BY p.id ASC LIMIT $limit OFFSET $offset";

// 執行查詢
$stmt = $pdo->prepare($sql);
if (isset($keyword)) {
    $stmt->bindParam(':keyword', $keyword, PDO::PARAM_STR);
    if (is_numeric($_GET['keyword'])) {
        $stmt->bindParam(':price_keyword', $price_keyword, PDO::PARAM_STR); // 绑定 price_keyword
    }
}
$stmt->execute();

// 顯示資料
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>商品列表</title>
    <link rel="stylesheet" href="../css/style.default.premium.css" id="theme-stylesheet">
    <link rel="stylesheet" href="../css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .main-content {
            margin: 2% 16%;
            max-width:80%;
            font-size: 20px;
        } 
        .producttitle:hover {
            text-decoration: none;
        }
        .add {
            background-color: #a31e1f;
            color: #fff;
        }
        .image-preview {
            display: flex;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .ratio {
            width: 100px;
            height: 0;
            padding-bottom: 100px;
            position: relative;
            margin-right: 10px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }
        .ratio img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        thead th {
            background-color: #393836 !important;
            color: white !important;
        }
        table {
            margin-top: 20px;
        }
        .price-pagination-form {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .price-filter {
            display: inline-block !important;
            width: 50%;
            text-align: right;
        }
        .pagination {
            display: inline-block;
            width: auto;
            flex-grow: 0;
        }
        .pagination a {
            padding: 10px 15px;
            margin: 0;
            background-color: #ffffff;
            color: #000000;
            text-decoration: none;
            border-radius: 0;
            border: 1px solid #cccccc;
            transition: transform 0.2s ease-in-out;
        }
        .pagination a:hover {
            transform: scale(1.1);
            border-color: #999999;
        }
        .pagination a.active,
        .pagination a:focus,
        .pagination a:active {
            transform: scale(1.1);
            background-color: #ffffff;
            color: #dc3545;
            border-color: #999999;
            cursor: default;
            outline: none;
        }
        .pagination a[disabled] {
            background-color: #f9f9f9;
            color: #cccccc;
            cursor: not-allowed;
            border-color: #f9f9f9;
        }
        #price_filter option:hover {
            background-color: #dc3545;
            color: #fff;
        }
    </style>
</head>
<body>
    <?php include("../../nav1.php") ?>
    <main class="main-content container">
        <div class="d-flex flex-column justify-content-between align-items-start mt-3 gap-2 mt-3 row content-wrapper">
            <div class="d-flex justify-content-between mb-3 col-12">
                <a href="product_list.php" class="producttitle">
                <p class="m-0 d-inline text-lg text-secondary">商品管理 /<span class="text-sm">商品列表</span></p>
                </a>
                <button type="button" class="btn add" data-toggle="modal" data-target="#addProductModal"><i class="fa-solid fa-plus"></i></button>
            </div>
            <div class="modal fade" id="addProductModal" tabindex="-1" role="dialog" aria-labelledby="addProductModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addProductModalLabel">新增商品</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label for="product_name">商品名稱:</label>
                                    <input type="text" class="form-control" id="product_name" name="product_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="brand_id">品牌:</label>
                                    <select class="form-control" id="brand_id" name="brand_id" required>
                                        <?php foreach ($brands as $brand): ?>
                                            <option value="<?php echo htmlspecialchars($brand['id']); ?>"><?php echo htmlspecialchars($brand['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="main_category_id">部位:</label>
                                    <select class="form-control" id="main_category_id" name="main_category_id" required onchange="updateSubcategories();">
                                        <?php foreach ($main_categories as $category): ?>
                                            <option value="<?php echo htmlspecialchars($category['id']); ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="sub_category_id">品項:</label>
                                    <select class="form-control" id="sub_category_id" name="sub_category_id" required>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="color">色號:</label>
                                    <input type="text" class="form-control" id="color" name="color" required>
                                </div>
                                <div class="form-group">
                                    <label for="price">價格:</label>
                                    <input type="number" class="form-control" id="price" name="price" step="0.01" required>
                                </div>
                                <div class="form-group">
                                    <label for="description">商品描述:</label>
                                    <input type="text" class="form-control" id="description" name="description" required>
                                </div>
                                <div class="form-group">
                                    <label for="images">上傳圖片:</label>
                                    <input type="file" class="form-control-file" id="images" name="images[]" accept="image/*" multiple required>
                                    <div id="image_preview" style="margin-top: 10px;" class="image_preview">
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                                <button type="submit" class="btn add" name="add_product">新增</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <hr>
             <div class="filters d-flex justify-content-between">
                <div class="pagination">
                    <?php
                    // 分頁時能保留，用戶的關鍵字搜尋
                    $keywordParam = isset($_GET['keyword']) ? "&keyword=" . urlencode($_GET['keyword']) : "";
                    $priceFilterParam = isset($_GET['price_filter']) ? "&price_filter=" . urlencode($_GET['price_filter']) : "";

                    if ($currentGroup > 1) {
                        $prevGroupPage = $startPage - 1;
                        echo "<a href='product_list.php?page=$prevGroupPage$keywordParam$priceFilterParam'>上一頁</a>";
                    }

                    for ($i = $startPage; $i <= $endPage; $i++) {
                        if ($i == $page) {
                            echo "<a class='active' href='javascript:void(0);'>$i</a>";
                        } else {
                            echo "<a href='product_list.php?page=$i$keywordParam$priceFilterParam'>$i</a>";
                        }
                    }

                    if ($endPage < $totalPages) {
                        $nextGroupPage = $endPage + 1;
                        echo "<a href='product_list.php?page=$nextGroupPage$keywordParam$priceFilterParam'>下一頁</a>";
                    }
                    ?>
                </div>

                <form method="GET" action="product_list.php" class="form-inline">
                    <input type="text" class="form-control mr-2" name="keyword" placeholder="搜尋產品名稱、品牌、部位或品項" value="<?php echo isset($_GET['keyword']) ? htmlspecialchars($_GET['keyword']) : ''; ?>">
                    <button type="submit" class="btn  mr-2"><i class="fa-solid fa-magnifying-glass"></i></button>
                </form>

                <form method="GET" action="product_list.php" class="form-inline">
                    <input type="hidden" name="keyword" value="<?php echo isset($_GET['keyword']) ? htmlspecialchars($_GET['keyword']) : ''; ?>">
                    <label for="price_filter" class="mr-2">依價格篩選:</label>
                    <select id="price_filter" name="price_filter" class="form-control mr-2">
                        <option value="">全部</option>
                        <option value="under_1000" <?php if (isset($_GET['price_filter']) && $_GET['price_filter'] == 'under_1000') echo 'selected'; ?>>1000 元以下</option>
                        <option value="1000_2000" <?php if (isset($_GET['price_filter']) && $_GET['price_filter'] == '1000_2000') echo 'selected'; ?>>1000 - 2000 元</option>
                        <option value="above_2000" <?php if (isset($_GET['price_filter']) && $_GET['price_filter'] == 'above_2000') echo 'selected'; ?>>2000 元以上</option>
                    </select>
                    <button type="submit" class="btn"><i class="fa-solid fa-filter-circle-dollar"></i></button>
                </form>
            </div>
            <div class="col-12">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>商品名稱</th>
                            <th>品牌</th>
                            <th>部位</th>
                            <th>品項</th>
                            <th>色號</th>
                            <th>價格</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['brand_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['main_category_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['sub_category_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['color_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['price']); ?></td>
                            <td class="gap-3">
                                <a href="product.php?id=<?= htmlspecialchars($row['id']) ?>&color_id=<?= htmlspecialchars($row['color_id']) ?>" class="btn btn-outline-secondary btn-md">
                                    <i class="fa-regular fa-eye"></i>
                                </a>
                                <a href="#" class="btn btn-outline-secondary btn-md" onclick="confirmDelete(<?= htmlspecialchars($row['id']) ?>, '<?= htmlspecialchars($row['product_name']) ?>')">
                                    <i class="fa-regular fa-trash-can"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        $('#main_category_id').change(function() {
            var categoryId = $(this).val();
            $.ajax({
                url: 'get_sub_categories.php',
                type: 'POST',
                data: { main_category_id: categoryId },
                dataType: 'json',
                success: function(response) {
                    var subCategorySelect = $('#sub_category_id');
                    subCategorySelect.empty();
                    if(response.sub_categories && response.sub_categories.length > 0) {
                        $.each(response.sub_categories, function(index, subCategory) {
                            subCategorySelect.append($('<option>').val(subCategory.id).text(subCategory.name));
                        });
                    } else {
                        subCategorySelect.append($('<option>').text('無子分類'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error("錯誤: " + status + " " + error);
                }
            });
        });

        $('#images').change(function() {
            $('#image_preview').empty();
            var files = this.files;

            if (files.length > 0) {
                $.each(files, function(index, file) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        var img = $('<img>').attr('src', e.target.result);
                        var container = $('<div>').addClass('ratio');
                        container.append(img);
                        $('#image_preview').append(container);
                    };
                    reader.readAsDataURL(file);
                });
            }
        });
    });

    function confirmDelete(productId, productName) {
        if (confirm(`確定要刪除"${productName}"嗎?`)) {
            $.ajax({
                url: 'delete_product.php',
                type: 'POST',
                data: { id: productId },
                success: function(response) {
                    if (response.success) {
                        alert('商品已成功刪除！');
                        location.reload();
                    } else {
                        alert('刪除失敗：' + response.error);
                    }
                },
                error: function(xhr, status, error) {
                    alert('刪除失敗：' + error);
                }
            });
        }
    }
    </script>
</body>
</html>
