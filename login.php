<?php include 'Header.php'; ?>
<?php include 'Menu.php'; ?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Log-In</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        </head>
        <div class="container">
            <body>
                <div class="row justify-content-center">
                    <div class="col-md-6 col-lg-4">
                        <div class="card-body p-4">
                            <h3 class="card-title mb-4 text-center">Login</h3>
                            <form action="process_login.php" method="post">
                                <label for="User_name" class="col-sm-4 col-form-label sr-only">User Name</label>
                                <div class="col-sm-12">
                                    <input type="text" class="form-control" id="User_name" name="User_name" placeholder="Username" required>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="Password" class="col-sm-4 col-form-label sr-only">Password</label>
                                <div class="col-sm-12">
                                    <input type="password" class="form-control" id="Password" name="Password" placeholder="Password" required>
                                </div>
                            </div>
                            <div class="form-group row">
                                <div class="col-sm-12 text-center">
                                    <button type="submit" class="btn btn-primary">Login</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </body>
</html>
<?php include 'Footer.php'; ?>